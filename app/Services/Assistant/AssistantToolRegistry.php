<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Contracts\AssistantTool;
use App\Models\User;
use Closure;

/**
 * The tools the assistant may call. Tools are registered once and filtered per user: a tool that requires
 * preview features is not offered (and cannot be called) for a user without them.
 */
final class AssistantToolRegistry
{
    /** @var list<Closure(User): AssistantTool> */
    private array $factories = [];

    /**
     * @param  Closure(User): AssistantTool  $factory
     */
    public function register(Closure $factory): void
    {
        $this->factories[] = $factory;
    }

    /**
     * @return array<string, AssistantTool>
     */
    public function forUser(User $user): array
    {
        $available = [];

        foreach ($this->factories as $factory) {
            $tool = $factory($user);

            if ($tool->requiresPreview() && ! $user->hasPreviewFeatures()) {
                continue;
            }

            $available[$tool->name()] = $tool;
        }

        return $available;
    }

    /**
     * @param  array<string, AssistantTool>  $tools
     * @return list<array<string, mixed>>
     */
    public function definitions(array $tools): array
    {
        return array_values(array_map(static fn (AssistantTool $tool): array => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
        ], $tools));
    }
}
