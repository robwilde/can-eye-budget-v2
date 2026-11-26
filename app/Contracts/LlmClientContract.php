<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Llm\LlmResponse;
use App\Exceptions\Assistant\LlmException;

interface LlmClientContract
{
    /**
     * One model call. A conversation is a list of items shaped as
     * ['role' => 'user'|'assistant'|'tool', 'content' => string, 'tool_calls' => list<LlmToolCall>, 'tool_call_id' => string, 'name' => string];
     * the last three keys appear only where they apply. Tools are provider-neutral definitions
     * ['name' => string, 'description' => string, 'parameters' => JSON schema array]; with $allowTools false they are still
     * described (a conversation holding tool results needs them) but the model is told not to call any. A call is never retried here.
     *
     * @param  list<array<string, mixed>>  $conversation
     * @param  list<array<string, mixed>>  $tools
     *
     * @throws LlmException
     */
    public function complete(string $system, array $conversation, array $tools = [], bool $allowTools = true): LlmResponse;

    public function provider(): string;
}
