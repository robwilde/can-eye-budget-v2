<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
final class ChatMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => Chat::factory(),
            'role' => ChatMessageRole::User,
            'content' => fake()->sentence(),
            'status' => ChatMessageStatus::Complete,
            'error_kind' => null,
            'model' => null,
        ];
    }

    public function assistant(): self
    {
        return $this->state(fn (): array => ['role' => ChatMessageRole::Assistant, 'model' => 'test-model']);
    }
}
