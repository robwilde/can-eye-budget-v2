<?php

declare(strict_types=1);

namespace App\Services\CsvImport;

final class CsvColumnMapper
{
    public const string FIELD_DATE = 'date';

    public const string FIELD_DESCRIPTION = 'description';

    public const string FIELD_AMOUNT = 'amount';

    public const string FIELD_DEBIT = 'debit';

    public const string FIELD_CREDIT = 'credit';

    public const string FIELD_BALANCE = 'balance';

    /**
     * Canonical fields → keyword fragments that commonly appear in headers.
     * Order matters: more-specific patterns should win.
     *
     * @var array<string, list<string>>
     */
    private const array PATTERNS = [
        self::FIELD_DATE => ['entered date', 'posted date', 'post date', 'transaction date', 'effective date', 'bank date', 'date'],
        self::FIELD_DESCRIPTION => ['transaction description', 'description', 'narrative', 'details', 'memo', 'reference'],
        self::FIELD_AMOUNT => ['amount', 'transaction amount', 'value'],
        self::FIELD_DEBIT => ['debit amount', 'debit', 'withdrawal', 'money out'],
        self::FIELD_CREDIT => ['credit amount', 'credit', 'deposit', 'money in'],
        self::FIELD_BALANCE => ['running balance', 'balance', 'closing balance'],
    ];

    /**
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public function suggest(array $headers): array
    {
        $suggested = [];
        $consumed = [];

        foreach (self::PATTERNS as $field => $patterns) {
            $suggested[$field] = $this->matchHeader($headers, $patterns, $consumed);

            if ($suggested[$field] !== null) {
                $consumed[] = $suggested[$field];
            }
        }

        return $suggested;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $patterns
     * @param  list<string>  $consumed
     */
    private function matchHeader(array $headers, array $patterns, array $consumed): ?string
    {
        foreach ($patterns as $pattern) {
            foreach ($headers as $header) {
                if (in_array($header, $consumed, true)) {
                    continue;
                }

                if (mb_strtolower(mb_trim($header)) === $pattern) {
                    return $header;
                }
            }
        }

        foreach ($patterns as $pattern) {
            foreach ($headers as $header) {
                if (in_array($header, $consumed, true)) {
                    continue;
                }

                if (str_contains(mb_strtolower(mb_trim($header)), $pattern)) {
                    return $header;
                }
            }
        }

        return null;
    }
}
