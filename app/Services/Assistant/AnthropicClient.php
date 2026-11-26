<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Contracts\LlmClientContract;
use App\DTOs\Llm\LlmResponse;
use App\DTOs\Llm\LlmToolCall;
use App\Enums\AssistantErrorKind;
use App\Exceptions\Assistant\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use stdClass;

final readonly class AnthropicClient implements LlmClientContract
{
    private const string VERSION = '2023-06-01';

    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private int $timeoutSeconds = 60,
        private int $connectTimeoutSeconds = 10,
        private int $maxTokens = 2048,
    ) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $conversation, array $tools = [], bool $allowTools = true): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new LlmException(AssistantErrorKind::Misconfigured);
        }

        $payload = ['model' => $this->model, 'max_tokens' => $this->maxTokens, 'system' => $system, 'messages' => $this->messages($conversation)];

        if ($tools !== []) {
            $payload['tools'] = array_map(static fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $tools);

            if (! $allowTools) {
                $payload['tool_choice'] = ['type' => 'none'];
            }
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => self::VERSION])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post(mb_rtrim($this->baseUrl, '/').'/messages', $payload);
        } catch (ConnectionException) {
            throw new LlmException(AssistantErrorKind::Unavailable);
        }

        if ($response->failed()) {
            throw LlmException::forStatus($response->status());
        }

        $text = '';
        $toolCalls = [];

        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = new LlmToolCall((string) ($block['id'] ?? ''), (string) ($block['name'] ?? ''), is_array($block['input'] ?? null) ? $block['input'] : []);
            }
        }

        if (! is_array($response->json('content'))) {
            throw new LlmException(AssistantErrorKind::Generic);
        }

        return new LlmResponse(
            content: $text,
            toolCalls: $toolCalls,
            promptTokens: (int) $response->json('usage.input_tokens', 0),
            completionTokens: (int) $response->json('usage.output_tokens', 0),
            model: (string) $response->json('model', $this->model),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $conversation
     * @return list<array<string, mixed>>
     */
    private function messages(array $conversation): array
    {
        $messages = [];

        foreach ($conversation as $item) {
            if ($item['role'] === 'tool') {
                $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $item['tool_call_id'], 'content' => $item['content']]]];

                continue;
            }

            if (! empty($item['tool_calls'])) {
                $blocks = $item['content'] === '' ? [] : [['type' => 'text', 'text' => $item['content']]];

                foreach ($item['tool_calls'] as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call->id, 'name' => $call->name, 'input' => $call->arguments === [] ? new stdClass : $call->arguments];
                }

                $messages[] = ['role' => 'assistant', 'content' => $blocks];

                continue;
            }

            $messages[] = ['role' => $item['role'], 'content' => $item['content']];
        }

        return $messages;
    }
}
