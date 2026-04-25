<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BankImportStatus;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankImport>
 */
final class BankImportFactory extends Factory
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
            'original_filename' => fake()->word().'.csv',
            'stored_path' => 'bank-imports/'.fake()->uuid().'.csv',
            'status' => BankImportStatus::Pending,
            'row_count' => 0,
            'imported_count' => 0,
            'skipped_count' => 0,
            'column_mapping' => [
                'date' => 'Entered Date',
                'description' => 'Transaction Description',
                'amount' => 'Amount',
                'balance' => 'Balance',
            ],
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankImportStatus::Completed,
            'row_count' => 100,
            'imported_count' => 100,
            'skipped_count' => 0,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankImportStatus::Failed,
            'error_summary' => 'Parsing error on row 23: invalid date',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function importing(): self
    {
        return $this->state(fn (array $attributes) => [
            'status' => BankImportStatus::Importing,
            'started_at' => now(),
        ]);
    }
}
