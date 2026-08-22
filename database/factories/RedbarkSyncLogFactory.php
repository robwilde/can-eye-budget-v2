<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RedbarkSyncLog>
 */
final class RedbarkSyncLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'redbark_feed_id' => RedbarkFeed::factory(),
            'user_id' => fn (array $attributes): int => (int) RedbarkFeed::query()
                ->whereKey($attributes['redbark_feed_id'])
                ->value('user_id'),
            'trigger' => RefreshTrigger::Manual,
            'status' => RefreshStatus::Pending,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefreshStatus::Success,
            'accounts_synced' => fake()->numberBetween(1, 5),
            'transactions_created' => fake()->numberBetween(0, 100),
            'transactions_updated' => fake()->numberBetween(0, 20),
            'balances_updated' => fake()->numberBetween(0, 5),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RefreshStatus::Failed,
            'errors' => [['context' => 'transactions', 'message' => 'Rate limit exceeded']],
        ]);
    }

    public function scheduled(): self
    {
        return $this->state(fn (array $attributes): array => [
            'trigger' => RefreshTrigger::Scheduled,
        ]);
    }
}
