<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionDirection;
use App\Models\BankImport;
use App\Models\BankImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankImportRow>
 */
final class BankImportRowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_import_id' => BankImport::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 1_000_000),
            'date' => '2026-03-05',
            'raw_date' => '05/03/2026',
            'amount' => -450,
            'raw_amount' => '-4.50',
            'direction' => TransactionDirection::Debit,
            'description' => fake()->company(),
        ];
    }

    public function invalidAmount(): self
    {
        return $this->state(fn (): array => [
            'amount' => null,
            'raw_amount' => 'abc',
            'direction' => null,
            'errors' => ['amount' => 'Amount "abc" is not a number'],
        ]);
    }
}
