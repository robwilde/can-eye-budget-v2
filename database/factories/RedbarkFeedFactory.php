<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RedbarkFeedStatus;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedbarkFeed>
 */
final class RedbarkFeedFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'api_key' => 'rbk_live_'.fake()->regexify('[a-z0-9]{24}'),
            'status' => RedbarkFeedStatus::Good,
            'pending_account_setup' => false,
            'last_synced_at' => null,
        ];
    }

    public function requiresUpdate(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RedbarkFeedStatus::RequiresUpdate,
        ]);
    }

    public function pendingSetup(): self
    {
        return $this->state(fn (array $attributes): array => [
            'pending_account_setup' => true,
        ]);
    }

    public function synced(): self
    {
        return $this->state(fn (array $attributes): array => [
            'last_synced_at' => now()->subHours(6),
        ]);
    }
}
