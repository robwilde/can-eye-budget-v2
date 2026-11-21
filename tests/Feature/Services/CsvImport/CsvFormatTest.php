<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\CsvFormat;
use App\Enums\CsvNumberFormat;
use App\Enums\CsvSignConvention;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvFormatDetector;
use App\Services\CsvImport\CsvNumberParser;
use App\Services\CsvImport\CsvParserService;

function formatCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv-format-').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function formatMapping(array $format = [], array $columns = []): array
{
    return [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
        ...(new CsvFormat(...$format))->toMapping(),
        ...$columns,
    ];
}

it('validates dates against the chosen date format', function () {
    $path = formatCsv("Date,Description,Amount\n15/03/2024,Coffee,-4.50\n");
    $parser = new CsvParserService;

    $dayFirst = $parser->summarize($path, formatMapping(['dateFormat' => 'd/m/Y']));
    $monthFirst = $parser->summarize($path, formatMapping(['dateFormat' => 'm/d/Y']));

    expect($dayFirst->errorRows)->toBe([])
        ->and($dayFirst->rowCount)->toBe(1)
        ->and($monthFirst->errorRows)->toHaveCount(1)
        ->and($monthFirst->errorRows[0]['error'])->toContain('does not match')
        ->and($monthFirst->rowCount)->toBe(0);
});

it('parses amounts using the chosen number format', function (string $cell, CsvNumberFormat $format, ?int $cents) {
    expect(app(CsvNumberParser::class)->parse($cell, $format))->toBe($cents);
})->with([
    'european' => ['1.234,56', CsvNumberFormat::DotThousandsCommaDecimal, 123456],
    'standard' => ['1,234.56', CsvNumberFormat::CommaThousandsDotDecimal, 123456],
    'space' => ['1 234,56', CsvNumberFormat::SpaceThousandsCommaDecimal, 123456],
    'non-breaking space' => ["1\u{00A0}234,56", CsvNumberFormat::SpaceThousandsCommaDecimal, 123456],
    'thousands only' => ['1,234', CsvNumberFormat::CommaThousandsOnly, 123400],
    'negative' => ['-1.234,56', CsvNumberFormat::DotThousandsCommaDecimal, -123456],
    'brackets' => ['(20.00)', CsvNumberFormat::CommaThousandsDotDecimal, -2000],
    'currency symbol' => ['$1,000.50', CsvNumberFormat::CommaThousandsDotDecimal, 100050],
    'plus sign' => ['+12.30', CsvNumberFormat::CommaThousandsDotDecimal, 1230],
    'plain integer' => ['42', CsvNumberFormat::CommaThousandsDotDecimal, 4200],
    'wrong separator for the format' => ['1.234,56', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'european read as standard decimal' => ['1,5', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'letters' => ['12abc', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'words' => ['n/a', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'blank' => ['', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'currency before the sign' => ['$-12.34', CsvNumberFormat::CommaThousandsDotDecimal, -1234],
    'code before the sign' => ['AUD -12.34', CsvNumberFormat::CommaThousandsDotDecimal, -1234],
    'currency before brackets' => ['$(12.34)', CsvNumberFormat::CommaThousandsDotDecimal, -1234],
    'three decimals' => ['12.345', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'european thousands read as standard' => ['1.234', CsvNumberFormat::CommaThousandsDotDecimal, null],
    'decimal in thousands-only' => ['1,234.50', CsvNumberFormat::CommaThousandsOnly, null],
]);

it('reads a european file end to end and marks a non-numeric amount invalid', function () {
    $path = formatCsv("Date;Description;Amount\n15/03/2024;Rent;-1.234,56\n16/03/2024;Broken;12abc\n");
    $mapping = formatMapping(['numberFormat' => CsvNumberFormat::DotThousandsCommaDecimal, 'delimiter' => ';']);
    $parser = new CsvParserService;

    $rows = $parser->preview($path, $mapping);
    $summary = $parser->summarize($path, $mapping);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->amount)->toBe(-123456)
        ->and($summary->errorRows)->toHaveCount(1)
        ->and($summary->errorRows[0]['error'])->toContain('not a number');
});

it('rejects rows dated after today', function () {
    $tomorrow = now()->addDay()->format('d/m/Y');
    $path = formatCsv("Date,Description,Amount\n{$tomorrow},Future,-1.00\n".now()->format('d/m/Y').",Today,-2.00\n");
    $parser = new CsvParserService;

    $summary = $parser->summarize($path, formatMapping());

    expect($summary->errorRows)->toHaveCount(1)
        ->and($summary->errorRows[0]['error'])->toContain('in the future')
        ->and($summary->rowCount)->toBe(1)
        ->and($parser->preview($path, formatMapping()))->toHaveCount(1);
});

it('applies the sign convention', function (CsvSignConvention $convention, string $cell, string $type, int $cents) {
    $path = formatCsv("Date,Description,Amount,Kind\n15/03/2024,Row,{$cell},{$type}\n");
    $mapping = formatMapping(['signConvention' => $convention, 'typeInflowValue' => 'Deposit'], ['type' => 'Kind']);

    $row = (new CsvParserService)->preview($path, $mapping)[0];

    expect($row->amount)->toBe($cents)
        ->and($row->direction->value)->toBe($cents < 0 ? 'debit' : 'credit');
})->with([
    'negative is debit' => [CsvSignConvention::NegativeIsDebit, '-10.00', 'x', -1000],
    'positive is credit' => [CsvSignConvention::NegativeIsDebit, '10.00', 'x', 1000],
    'negative is credit' => [CsvSignConvention::NegativeIsCredit, '-10.00', 'x', 1000],
    'positive is debit' => [CsvSignConvention::NegativeIsCredit, '10.00', 'x', -1000],
    'type column inflow' => [CsvSignConvention::TypeColumn, '10.00', 'deposit', 1000],
    'type column outflow' => [CsvSignConvention::TypeColumn, '10.00', 'Withdrawal', -1000],
]);

it('skips leading rows before the headers and counts the lines', function () {
    $path = formatCsv("Statement for account 1\nGenerated today\nDate,Description,Amount\n15/03/2024,Coffee,-4.50\n");
    $format = new CsvFormat(skipRows: 2);
    $parser = new CsvParserService;

    expect($parser->headers($path, $format))->toBe(['Date', 'Description', 'Amount'])
        ->and($parser->lineCount($path, $format))->toBe(4)
        ->and($parser->preview($path, formatMapping(['skipRows' => 2])))->toHaveCount(1);
});

it('detects only the date formats every sample fits', function () {
    $detector = new CsvFormatDetector;

    expect($detector->dateFormats(['15/03/2024', '01/02/2024']))->toBe(['d/m/Y'])
        ->and($detector->dateFormats(['03/04/2024']))->toContain('d/m/Y', 'm/d/Y')
        ->and($detector->dateFormats(['2024-03-15']))->toBe(['Y-m-d'])
        ->and($detector->dateFormats(['15 Mar 2024']))->toBe(['d M Y'])
        ->and($detector->dateFormats(['not a date']))->toBe([])
        ->and($detector->dateFormats([]))->toBe([])
        ->and($detector->delimiter("Date;Description;Amount\n"))->toBe(';')
        ->and($detector->delimiter('Date,Description,Amount'))->toBe(',');
});

it('round trips a format through a column mapping and ignores a mapping without one', function () {
    $format = new CsvFormat('Y-m-d', CsvNumberFormat::SpaceThousandsCommaDecimal, CsvSignConvention::TypeColumn, 3, ';', 'in');

    expect(CsvFormat::fromMapping($format->toMapping())?->toMapping())->toBe($format->toMapping())
        ->and(CsvFormat::fromMapping(['date' => 'Date', 'amount' => 'Amount']))->toBeNull();
});

it('gives identical rows in one file distinct hashes and keeps them stable across parses', function () {
    $path = formatCsv("Date,Description,Amount\n15/03/2024,Coffee,-4.50\n15/03/2024,Coffee,-4.50\n15/03/2024,Coffee,-4.50\n");
    $parser = new CsvParserService;

    $first = array_map(fn ($row) => $row->csvHash, $parser->preview($path, formatMapping()));
    $second = array_map(fn ($row) => $row->csvHash, $parser->preview($path, formatMapping()));

    expect(array_unique($first))->toHaveCount(3)
        ->and($second)->toBe($first);
});

it('imports through summarize and preview when leading rows are skipped', function () {
    $path = formatCsv("Statement for account 1\nGenerated today\nDate,Description,Amount\n15/03/2024,Coffee,-4.50\n16/03/2024,Tea,-3.00\n");
    $parser = new CsvParserService;
    $mapping = formatMapping(['skipRows' => 2]);

    $summary = $parser->summarize($path, $mapping);

    expect($summary->errorRows)->toBe([])
        ->and($summary->rowCount)->toBe(2)
        ->and($parser->sampleValues($path, 'Date', new CsvFormat(skipRows: 2)))->toBe(['15/03/2024', '16/03/2024'])
        ->and(iterator_count($parser->eachRow($path, $mapping)))->toBe(2);
});

it('does not read a two-digit year as a four-digit one', function () {
    $detector = new CsvFormatDetector;

    expect($detector->dateFormats(['15/03/24']))->toBe(['d/m/y'])
        ->and($detector->dateFormats(['2024-03-15']))->toBe(['Y-m-d'])
        ->and($detector->dateFormats(['15-Mar-2024']))->toBe(['d-M-Y']);
});

it('marks an unreadable debit or credit cell invalid instead of importing zero', function () {
    $path = formatCsv("Date,Description,Debit,Credit\n15/03/2024,Rent,\"1.234,56\",0.00\n");
    $mapping = ['date' => 'Date', 'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit', ...(new CsvFormat)->toMapping()];

    $summary = (new CsvParserService)->summarize($path, $mapping);

    expect($summary->errorRows)->toHaveCount(1)
        ->and($summary->rowCount)->toBe(0);
});

it('finds the date column when headers have surrounding spaces', function () {
    $path = formatCsv("Description, Date ,Amount\nCoffee,2024-03-15,-4.50\n");

    expect((new CsvParserService)->sampleValues($path, 'Date'))->toBe(['2024-03-15']);
});

it('keeps only the format entries of a mapping', function () {
    $kept = CsvFormat::keepFormat(['date' => 'Date', 'date_format' => 'Y-m-d', 'delimiter' => ';', 'type' => 'Kind']);

    expect($kept)->toBe(['date_format' => 'Y-m-d', 'delimiter' => ';', 'type' => 'Kind']);
});
