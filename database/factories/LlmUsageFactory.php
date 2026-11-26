<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LlmOperation;
use App\Models\LlmUsage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LlmUsage>
 */
final class LlmUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'chat_message_id' => null,
            'operation' => LlmOperation::Chat,
            'provider' => 'test',
            'model' => 'test-model',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cost' => 12,
        ];
    }
}
