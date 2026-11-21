<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatementLineKind;
use App\Enums\TransactionDirection;
use App\Models\PdfImport;
use App\Models\PdfImportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PdfImportLine>
 */
final class PdfImportLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pdf_import_id' => PdfImport::factory(),
            'line_date' => '2026-03-05',
            'raw_date' => '05/03/2026',
            'date_unreadable' => false,
            'amount' => -4500,
            'direction' => TransactionDirection::Debit,
            'description' => fake()->company(),
            'kind' => StatementLineKind::StatementOnly,
        ];
    }

    public function unreadableDate(): self
    {
        return $this->state(fn (): array => [
            'line_date' => null,
            'raw_date' => '31/02/2026',
            'date_unreadable' => true,
        ]);
    }
}
