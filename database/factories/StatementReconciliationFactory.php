<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatementReconciliationStatus;
use App\Models\Account;
use App\Models\StatementReconciliation;
use App\Models\User;
use App\Services\CsvImport\CsvColumnMapper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatementReconciliation>
 */
final class StatementReconciliationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'account_id' => Account::factory()->for($user),
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => StatementReconciliationStatus::Open,
            'original_filename' => 'statement.csv',
            'stored_path' => 'statement-reconciliations/'.fake()->uuid().'.csv',
            'column_mapping' => [
                CsvColumnMapper::FIELD_DATE => 'Date',
                CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
                CsvColumnMapper::FIELD_AMOUNT => 'Amount',
            ],
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StatementReconciliationStatus::Closed,
            'closed_at' => now(),
        ]);
    }
}
