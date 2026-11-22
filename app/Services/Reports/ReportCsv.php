<?php

declare(strict_types=1);

namespace App\Services\Reports;

use League\Csv\EscapeFormula;

/**
 * Lays the monthly category breakdown out as CSV rows: a header of month columns and a
 * total, an income section, an expenses section and a net row. Amounts are integer cents
 * written as decimals, and text cells that a spreadsheet could read as a formula are escaped.
 */
final class ReportCsv
{
    /**
     * @param  list<string>  $months  `Y-m` keys, one column each
     * @param  array{rows: list<array{name: string, total: int, months: array<string, int>}>, total: int, months: array<string, int>}  $income
     * @param  array{rows: list<array{name: string, total: int, months: array<string, int>}>, total: int, months: array<string, int>}  $expenses
     * @return list<list<string>>
     */
    public static function rows(array $months, array $income, array $expenses): array
    {
        $escape = new EscapeFormula;
        $text = static fn (string $cell): string => $escape->escapeRecord([$cell])[0];

        $rows = [['Category', ...$months, 'Total']];

        foreach ([['Income', 'Total income', $income], ['Expenses', 'Total expenses', $expenses]] as [$heading, $totalLabel, $section]) {
            $rows[] = [$heading];

            foreach ($section['rows'] as $row) {
                $rows[] = [$text($row['name']), ...self::cells($months, $row['months']), self::decimal($row['total'])];
            }

            $rows[] = [$totalLabel, ...self::cells($months, $section['months']), self::decimal($section['total'])];
        }

        $net = [];

        foreach ($months as $month) {
            $net[$month] = ($income['months'][$month] ?? 0) - ($expenses['months'][$month] ?? 0);
        }

        $rows[] = ['Net', ...self::cells($months, $net), self::decimal($income['total'] - $expenses['total'])];

        return $rows;
    }

    /**
     * @param  list<string>  $months
     * @param  array<string, int>  $values
     * @return list<string>
     */
    private static function cells(array $months, array $values): array
    {
        return array_map(static fn (string $month): string => self::decimal($values[$month] ?? 0), $months);
    }

    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
