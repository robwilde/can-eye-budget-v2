<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\StatementMetadata;
use App\Enums\StatementFileType;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;
use App\Support\Currencies;
use Carbon\CarbonImmutable;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads what it can from a stored statement: the first two pages of a PDF (English, German,
 * French, Spanish and Dutch labels), the date column of a CSV, and the filename for anything
 * left over. Spreadsheets are read from the filename only.
 */
final class StatementMetadataReader
{
    private const int PDF_PAGES = 2;

    private const int MAX_PERIOD_DAYS = 400;

    private const int PDF_DECODE_MEMORY_LIMIT = 8 * 1024 * 1024;

    private const int EARLIEST_YEAR = 1990;

    private const array OPENING_LABELS = [
        'opening balance', 'balance brought forward', 'previous balance', 'balance b/f',
        'anfangssaldo', 'alter kontostand', 'kontostand am anfang',
        'solde initial', 'ancien solde', 'solde précédent', 'solde precedent',
        'saldo inicial', 'saldo anterior',
        'beginsaldo', 'openingssaldo', 'vorig saldo',
    ];

    private const array CLOSING_LABELS = [
        'closing balance', 'balance carried forward', 'new balance', 'ending balance', 'balance c/f',
        'endsaldo', 'schlusssaldo', 'neuer kontostand', 'kontostand am ende',
        'solde final', 'nouveau solde', 'solde de clôture', 'solde de cloture',
        'saldo final', 'nuevo saldo',
        'eindsaldo', 'nieuw saldo', 'slotsaldo',
    ];

    private const array CREDIT_LABELS = [
        'total credits', 'total deposits', 'total money in', 'money in',
        'summe gutschriften', 'gesamtgutschriften',
        'total des crédits', 'total des credits', 'total crédits',
        'total abonos', 'total ingresos',
        'totaal bij', 'totaal tegoed',
    ];

    private const array DEBIT_LABELS = [
        'total debits', 'total withdrawals', 'total money out', 'money out',
        'summe lastschriften', 'gesamtbelastungen',
        'total des débits', 'total des debits', 'total débits',
        'total cargos', 'total pagos',
        'totaal af', 'totaal debet',
    ];

    private const array ACCOUNT_LABELS = [
        'ending in', 'account number', 'account no', 'acct', 'card number', 'account',
        'kontonummer', 'konto', 'iban',
        'numéro de compte', 'numero de compte', 'compte',
        'número de cuenta', 'numero de cuenta', 'cuenta',
        'rekeningnummer', 'rekening',
    ];

    private const array FILENAME_STOP_WORDS = [
        'statement', 'statements', 'estatement', 'account', 'export', 'transactions', 'transaction',
        'download', 'bank', 'copy', 'final', 'pdf', 'csv', 'xlsx',
    ];

    private const array MONTHS = [
        'jan' => 1, 'januar' => 1, 'january' => 1, 'janvier' => 1, 'enero' => 1, 'januari' => 1,
        'feb' => 2, 'februar' => 2, 'february' => 2, 'février' => 2, 'fevrier' => 2, 'febrero' => 2, 'februari' => 2,
        'mar' => 3, 'mär' => 3, 'märz' => 3, 'marz' => 3, 'march' => 3, 'mars' => 3, 'marzo' => 3, 'maart' => 3, 'mrt' => 3,
        'apr' => 4, 'april' => 4, 'avril' => 4, 'abril' => 4,
        'may' => 5, 'mai' => 5, 'mayo' => 5, 'mei' => 5,
        'jun' => 6, 'juni' => 6, 'june' => 6, 'juin' => 6, 'junio' => 6,
        'jul' => 7, 'juli' => 7, 'july' => 7, 'juillet' => 7, 'julio' => 7,
        'aug' => 8, 'august' => 8, 'août' => 8, 'aout' => 8, 'agosto' => 8, 'augustus' => 8, 'ago' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9, 'septembre' => 9, 'septiembre' => 9, 'setiembre' => 9,
        'oct' => 10, 'okt' => 10, 'october' => 10, 'oktober' => 10, 'octobre' => 10, 'octubre' => 10,
        'nov' => 11, 'november' => 11, 'novembre' => 11, 'noviembre' => 11,
        'dec' => 12, 'déc' => 12, 'ene' => 1, 'janv' => 1, 'févr' => 2, 'fevr' => 2, 'avr' => 4, 'abr' => 4, 'juil' => 7,
        'dez' => 12, 'december' => 12, 'dezember' => 12, 'décembre' => 12, 'decembre' => 12, 'dic' => 12, 'diciembre' => 12,
    ];

    private const string DATE_PATTERN = '(?:\d{4}-\d{2}-\d{2}|\d{1,2}[./-]\d{1,2}[./-]\d{2,4}|\d{1,2}(?:st|nd|rd|th|er)?\.?\s+(?:de\s+)?\p{L}{3,10}\.?\s+(?:de\s+)?\d{4})';

    public function __construct(
        private readonly CsvParserService $csv,
        private readonly CsvColumnMapper $mapper,
    ) {}

    public function read(string $absolutePath, StatementFileType $type, string $originalFilename): StatementMetadata
    {
        $fromFile = match ($type) {
            StatementFileType::Pdf => $this->fromPdf($absolutePath),
            StatementFileType::Csv => $this->fromCsv($absolutePath),
            StatementFileType::Xlsx => new StatementMetadata,
        };

        $fromName = $this->fromFilename($originalFilename);

        return new StatementMetadata(
            periodStart: $fromFile->periodStart ?? $fromName->periodStart,
            periodEnd: $fromFile->periodEnd ?? $fromName->periodEnd,
            openingBalance: $fromFile->openingBalance,
            closingBalance: $fromFile->closingBalance,
            currency: $fromFile->currency,
            lastFour: $fromFile->lastFour ?? $fromName->lastFour,
            institution: $fromFile->institution ?? $fromName->institution,
            accountName: $fromFile->accountName,
        );
    }

    private function fromCsv(string $path): StatementMetadata
    {
        try {
            $mapping = $this->mapper->suggest($this->csv->headers($path));

            if (($mapping[CsvColumnMapper::FIELD_DATE] ?? null) === null) {
                return new StatementMetadata;
            }

            $summary = $this->csv->summarize($path, $mapping);

            return new StatementMetadata(periodStart: $summary->earliestDate, periodEnd: $summary->latestDate);
        } catch (Throwable) {
            return new StatementMetadata;
        }
    }

    private function fromPdf(string $path): StatementMetadata
    {
        try {
            $config = new Config;
            $config->setDecodeMemoryLimit(self::PDF_DECODE_MEMORY_LIMIT);
            $config->setRetainImageContent(false);

            $text = '';

            foreach (array_slice((new Parser([], $config))->parseFile($path)->getPages(), 0, self::PDF_PAGES) as $page) {
                $text .= "\n".$page->getText();
            }
        } catch (Throwable) {
            return new StatementMetadata;
        }

        $text = mb_trim($text);

        if ($text === '') {
            return new StatementMetadata;
        }

        [$periodStart, $periodEnd] = $this->periodIn($text);
        [$opening, $closing, $currency] = $this->balancesIn($text);

        return new StatementMetadata(
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            openingBalance: $opening,
            closingBalance: $closing,
            currency: $currency ?? $this->labelledCurrencyIn($text),
            lastFour: $this->lastFourIn($text),
        );
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function periodIn(string $text): array
    {
        $pattern = '~('.self::DATE_PATTERN.')\s*(?:to|through|bis|au|al|a|tot|-|–|—)\s*('.self::DATE_PATTERN.')~iu';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [null, null];
        }

        foreach ($matches as $match) {
            $start = $this->parseDate($match[1]);
            $end = $this->parseDate($match[2]);

            if ($start !== null && $end !== null && $end->greaterThanOrEqualTo($start)
                && $start->diffInDays($end) <= self::MAX_PERIOD_DAYS) {
                return [$start, $end];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function balancesIn(string $text): array
    {
        $opening = $this->amountAfter($text, self::OPENING_LABELS);
        $closing = $this->amountAfter($text, self::CLOSING_LABELS);

        if ($opening === null && $closing === null) {
            return [null, null, null];
        }

        $found = array_filter([
            $opening,
            $closing,
            $this->amountAfter($text, self::CREDIT_LABELS),
            $this->amountAfter($text, self::DEBIT_LABELS),
        ]);

        $currencies = array_unique(array_filter(array_map(static fn (array $hit): ?string => $hit['currency'], $found)));

        if (count($currencies) > 1) {
            return [null, null, null];
        }

        $credits = $this->amountAfter($text, self::CREDIT_LABELS);
        $debits = $this->amountAfter($text, self::DEBIT_LABELS);

        if ($opening !== null && $closing !== null && $credits !== null && $debits !== null
            && $closing['cents'] !== $opening['cents'] + abs($credits['cents']) - abs($debits['cents'])) {
            return [null, null, null];
        }

        return [$opening['cents'] ?? null, $closing['cents'] ?? null, array_values($currencies)[0] ?? null];
    }

    /**
     * @param  list<string>  $labels
     * @return array{cents: int, currency: ?string}|null
     */
    private function amountAfter(string $text, array $labels): ?array
    {
        $alternatives = implode('|', array_map(static fn (string $label): string => preg_quote($label, '~'), $labels));
        $pattern = '~(?:(?:^|\n)\h*(?:'.$alternatives.')[\h:]*\n\h*|(?:'.$alternatives.')[^\p{L}\d\-\x{2212}\(\n]{0,40}?)(?<amount>\(?[\-\x{2212}]?\h?(?<pre>[€£$]|[A-Z]{3})?\h?(?:\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,2})?|\d[\d.,\']*)\)?[\-\x{2212}]?)\h*(?<post>CR|DR|[A-Z]{3})?(?![\p{L}\d])~iu';

        if (preg_match($pattern, $text, $match) !== 1) {
            return null;
        }

        $cents = $this->parseAmount($match['amount'], mb_strtoupper($match['post'] ?? ''));

        if ($cents === null) {
            return null;
        }

        $code = $this->currencyCode($match['pre'] ?? '') ?? $this->currencyCode($match['post'] ?? '');

        return ['cents' => $cents, 'currency' => $code];
    }

    private function currencyCode(string $token): ?string
    {
        $token = mb_strtoupper(mb_trim($token));

        return match (true) {
            $token === '€' => 'EUR',
            $token === '£' => 'GBP',
            mb_strlen($token) === 3 && ctype_alpha($token) && Currencies::isKnown($token) => $token,
            default => null,
        };
    }

    private function labelledCurrencyIn(string $text): ?string
    {
        if (preg_match('/(?:currency|währung|devise|moneda|valuta)\s*[:\-]?\s*([A-Z]{3})\b/iu', $text, $match) === 1) {
            $code = mb_strtoupper($match[1]);

            return Currencies::isKnown($code) ? $code : null;
        }

        return null;
    }

    private function lastFourIn(string $text): ?string
    {
        $alternatives = implode('|', array_map(static fn (string $label): string => preg_quote($label, '~'), self::ACCOUNT_LABELS));

        if (preg_match_all('/\b(?:'.$alternatives.')\b[^\p{L}\n\d*•]{0,25}\n?\h*([\d*xX•\h\-]{4,40})(?![\d.,\/])/iu', $text, $matches) === 0) {
            return null;
        }

        foreach ($matches[1] as $candidate) {
            $candidate = mb_rtrim($candidate);

            if (preg_match('/(\d{4})$/', $candidate, $last) === 1) {
                return $last[1];
            }
        }

        return null;
    }

    private function parseAmount(string $raw, string $suffix): ?int
    {
        $negative = str_contains($raw, '-') || str_contains($raw, "\u{2212}") || str_starts_with(mb_trim($raw), '(') || $suffix === 'DR';
        $digits = preg_replace('/[^\d.,\']/u', '', $raw) ?? '';
        $digits = mb_rtrim(str_replace("'", '', $digits), '.,');

        if ($digits === '' || preg_match('/\d/', $digits) !== 1) {
            return null;
        }

        $lastDot = mb_strrpos($digits, '.');
        $lastComma = mb_strrpos($digits, ',');
        $decimalAt = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($decimalAt >= 0 && mb_strlen($digits) - $decimalAt - 1 <= 2 && mb_strlen($digits) - $decimalAt - 1 > 0) {
            $whole = preg_replace('/[.,]/', '', mb_substr($digits, 0, $decimalAt)) ?? '';
            $fraction = mb_str_pad(mb_substr($digits, $decimalAt + 1), 2, '0');
        } else {
            $whole = preg_replace('/[.,]/', '', $digits) ?? '';
            $fraction = '00';
        }

        if ($whole === '' || mb_strlen($whole) > 15) {
            return null;
        }

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function parseDate(string $raw): ?CarbonImmutable
    {
        $raw = mb_strtolower(mb_trim($raw));

        try {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
                return $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
            }

            if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/', $raw, $m) === 1) {
                $year = (int) $m[3];

                return $this->makeDate($year < 100 ? 2000 + $year : $year, (int) $m[2], (int) $m[1]);
            }

            if (preg_match('/^(\d{1,2})(?:st|nd|rd|th|er)?\.?\s+(?:de\s+)?(\p{L}{3,10})\.?\s+(?:de\s+)?(\d{4})$/u', $raw, $m) === 1) {
                $month = self::MONTHS[$m[2]] ?? null;

                return $month === null ? null : $this->makeDate((int) $m[3], $month, (int) $m[1]);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function makeDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day)->startOfDay() : null;
    }

    private function fromFilename(string $filename): StatementMetadata
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        [$start, $end] = $this->periodInFilename($stem);

        return new StatementMetadata(
            periodStart: $start,
            periodEnd: $end,
            lastFour: $this->lastFourInFilename($stem),
            institution: $this->institutionInFilename($stem),
        );
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function periodInFilename(string $stem): array
    {
        $normalised = str_replace('_', '-', $stem);

        if (preg_match('/(\d{4})-(\d{2})-(\d{2})\D+(\d{4})-(\d{2})-(\d{2})/', $normalised, $m) === 1) {
            $start = $this->makeDate((int) $m[1], (int) $m[2], (int) $m[3]);
            $end = $this->makeDate((int) $m[4], (int) $m[5], (int) $m[6]);

            if ($start !== null && $end !== null && $end->greaterThanOrEqualTo($start)) {
                return [$start, $end];
            }
        }

        if (preg_match('/(?<!\d)(\d{4})[-.](\d{2})(?![\d])/', $normalised, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return $this->monthRange((int) $m[1], (int) $m[2]);
        }

        if (preg_match('/(\p{L}{3,10})[\s\-.]*(\d{4})(?!\d)/u', mb_strtolower($normalised), $m) === 1 && isset(self::MONTHS[$m[1]])) {
            return $this->monthRange((int) $m[2], self::MONTHS[$m[1]]);
        }

        return [null, null];
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function monthRange(int $year, int $month): array
    {
        if ($year < self::EARLIEST_YEAR || $year > CarbonImmutable::now()->year + 1) {
            return [null, null];
        }

        $start = $this->makeDate($year, $month, 1);

        return $start === null ? [null, null] : [$start, $start->endOfMonth()->startOfDay()];
    }

    private function lastFourInFilename(string $stem): ?string
    {
        if (preg_match('/(?:x{2,}|\*{2,}|ending|last4|acct|account|\.{2,})[\s_\-]*(\d{4})(?!\d|[\-._]\d{2}(?!\d))/i', $stem, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function institutionInFilename(string $stem): ?string
    {
        $words = [];

        foreach (preg_split('/[\s_\-.]+/', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^\p{L}+$/u', $token) !== 1 || isset(self::MONTHS[mb_strtolower($token)])) {
                break;
            }

            if (in_array(mb_strtolower($token), self::FILENAME_STOP_WORDS, true)) {
                if ($words === []) {
                    continue;
                }

                break;
            }

            $words[] = $token;
        }

        return $words === [] ? null : implode(' ', $words);
    }
}
