<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

use App\Models\BankImport;
use Carbon\CarbonImmutable;

/**
 * The preview summary of an import read from its stored rows, for files that are not read
 * column by column (QIF): the valid rows, their date range and totals, and what is invalid.
 */
final class BankImportRowSummary
{
    private const int LISTED_ERRORS = 5;

    public function for(BankImport $import): PreviewSummary
    {
        $valid = fn () => $import->rows()->whereNull('errors');
        $earliest = $valid()->min('date');
        $latest = $valid()->max('date');
        $errors = $import->rows()->invalid()->orderBy('row_number')->limit(self::LISTED_ERRORS)->get(['row_number', 'errors'])
            ->map(fn ($row): array => ['row' => $row->row_number, 'error' => (string) collect($row->errors)->first()])
            ->all();

        return new PreviewSummary(
            rowCount: $valid()->count(),
            earliestDate: $earliest === null ? null : CarbonImmutable::parse($earliest),
            latestDate: $latest === null ? null : CarbonImmutable::parse($latest),
            totalDebits: abs((int) $valid()->where('amount', '<', 0)->sum('amount')),
            totalCredits: (int) $valid()->where('amount', '>', 0)->sum('amount'),
            duplicateCount: 0,
            errorRows: $errors,
        );
    }
}
