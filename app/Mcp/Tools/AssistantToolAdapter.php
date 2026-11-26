<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Contracts\AssistantTool;
use App\Contracts\AssistantWriteTool;
use App\Enums\AuditOutcome;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * One in-app assistant tool offered over MCP. The name, description and schema are the tool's own; a call runs the
 * tool as the key's owner and is audited by tool name. A thrown error is reported, never described to the client.
 */
final class AssistantToolAdapter extends Tool
{
    public function __construct(
        private readonly AssistantTool $tool,
        private readonly User $user,
        private readonly bool $mayWrite,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function title(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = $this->tool->parameters();
        $schema['properties'] = (object) ($schema['properties'] ?? []);

        return [
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'inputSchema' => $schema,
            'annotations' => $this->tool instanceof AssistantWriteTool ? ['readOnlyHint' => false, 'destructiveHint' => true] : ['readOnlyHint' => true],
        ];
    }

    public function handle(Request $request): Response
    {
        if ($this->tool instanceof AssistantWriteTool && ! $this->mayWrite) {
            return Response::error('This key is read-only.');
        }

        try {
            $result = $this->tool->handle($this->user, $request->all());
            $this->audit(array_key_exists('error', $result) ? AuditOutcome::Failure : AuditOutcome::Success);

            return Response::text(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            report($exception);
            $this->audit(AuditOutcome::Failure);

            return Response::error('That could not be done.');
        }
    }

    private function audit(AuditOutcome $outcome): void
    {
        app(AuditRecorder::class)->recordSafely('mcp.'.$this->tool->name(), outcome: $outcome, actorId: $this->user->id);
    }
}
