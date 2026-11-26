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

final readonly class OpenAiClient implements LlmClientContract
{
    public function __construct(
        private ?string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com/v1',
        private int $timeoutSeconds = 60,
        private int $connectTimeoutSeconds = 10,
        private ?int $maxTokens = null,
    ) {}

    public function provider(): string
    {
        return 'openai';
    }

    public function complete(string $system, array $conversation, array $tools = [], bool $allowTools = true): LlmResponse
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new LlmException(AssistantErrorKind::Misconfigured);
        }

        $payload = ['model' => $this->model, 'messages' => [['role' => 'system', 'content' => $system], ...$this->messages($conversation)]];

        if ($this->maxTokens !== null) {
            $payload['max_completion_tokens'] = $this->maxTokens;
        }

        if ($tools !== []) {
            $payload['tools'] = array_map(static fn (array $tool): array => ['type' => 'function', 'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ]], $tools);

            if (! $allowTools) {
                $payload['tool_choice'] = 'none';
            }
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post(mb_rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new LlmException(AssistantErrorKind::Unavailable);
        }

        if ($response->failed()) {
            throw LlmException::forStatus($response->status());
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw new LlmException(AssistantErrorKind::Generic);
        }

        $toolCalls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $toolCalls[] = new LlmToolCall((string) ($call['id'] ?? ''), (string) ($call['function']['name'] ?? ''), is_array($arguments) ? $arguments : []);
        }

        return new LlmResponse(
            content: (string) ($message['content'] ?? ''),
            toolCalls: $toolCalls,
            promptTokens: (int) $response->json('usage.prompt_tokens', 0),
            completionTokens: (int) $response->json('usage.completion_tokens', 0),
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
                $messages[] = ['role' => 'tool', 'tool_call_id' => $item['tool_call_id'], 'content' => $item['content']];

                continue;
            }

            $message = ['role' => $item['role'], 'content' => $item['content']];

            if (! empty($item['tool_calls'])) {
                $message['tool_calls'] = array_map(static fn (LlmToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => ['name' => $call->name, 'arguments' => json_encode($call->arguments === [] ? new stdClass : $call->arguments, JSON_THROW_ON_ERROR)],
                ], $item['tool_calls']);
            }

            $messages[] = $message;
        }

        return $messages;
    }
}
