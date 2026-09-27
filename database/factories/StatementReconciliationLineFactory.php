<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatementLineKind;
use App\Models\StatementReconciliation;
use App\Models\StatementReconciliationLine;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementReconciliationLine>
 */
final class StatementReconciliationLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'statement_reconciliation_id' => StatementReconciliation::factory(),
            'kind' => StatementLineKind::StatementOnly,
            'csv_hash' => hash('sha256', fake()->uuid()),
            'post_date' => '2026-01-15',
            'amount' => -fake()->numberBetween(100, 50000),
            'description' => fake()->company(),
        ];
    }

    public function matched(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => StatementLineKind::Matched,
            'transaction_id' => Transaction::factory(),
        ]);
    }

    public function statementOnly(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => StatementLineKind::StatementOnly,
            'transaction_id' => null,
        ]);
    }

    public function feedOnly(): self
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => StatementLineKind::FeedOnly,
            'transaction_id' => Transaction::factory(),
            'csv_hash' => null,
        ]);
    }

    public function checked(): self
    {
        return $this->state(fn (array $attributes): array => [
            'checked_at' => now(),
        ]);
    }
}
