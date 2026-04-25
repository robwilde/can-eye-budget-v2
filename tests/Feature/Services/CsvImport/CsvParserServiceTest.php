<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Services\CsvImport\CsvColumnMapper;
use App\Services\CsvImport\CsvParserService;

function tmpCsv(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv-test-').'.csv';
    file_put_contents($path, $content);

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/csv-test-*.csv') ?: [] as $file) {
        @unlink($file);
    }
});

test('headers strips BOM and trims whitespace', function () {
    $bom = "\xEF\xBB\xBF";
    $path = tmpCsv($bom."Date , Amount\n01/01/2026, 10.00\n");

    $parser = new CsvParserService();
    $headers = $parser->headers($path);

    expect($headers)->toBe(['Date', 'Amount']);
});

test('preview parses Westpac fixture rows', function () {
    $fixture = base_path('tests/Fixtures/StatementCsv-Westpac.csv');

    $parser = new CsvParserService();
    $rows = $parser->preview($fixture, [
        CsvColumnMapper::FIELD_DATE => 'Entered Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Transaction Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
        CsvColumnMapper::FIELD_BALANCE => 'Balance',
    ], limit: 3);

    expect($rows)->toHaveCount(3);

    expect($rows[0]->postDate->format('Y-m-d'))->toBe('2026-01-01')
        ->and($rows[0]->amount)->toBe(-2899)
        ->and($rows[0]->direction)->toBe(TransactionDirection::Debit)
        ->and($rows[0]->balance)->toBe(93720)
        ->and($rows[0]->description)->toContain('Netflix');
});

test('signed amount in negative form parses as debit', function () {
    $path = tmpCsv("Date,Description,Amount\n01/01/2026,COFFEE,-4.50\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->amount)->toBe(-450)
        ->and($rows[0]->direction)->toBe(TransactionDirection::Debit);
});

test('signed amount in positive form parses as credit', function () {
    $path = tmpCsv("Date,Description,Amount\n01/01/2026,SALARY,1500.00\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->amount)->toBe(150000)
        ->and($rows[0]->direction)->toBe(TransactionDirection::Credit);
});

test('split debit credit columns parse correctly', function () {
    $path = tmpCsv("Date,Description,Debit,Credit\n01/01/2026,COFFEE,4.50,\n02/01/2026,SALARY,,1500.00\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_DEBIT => 'Debit',
        CsvColumnMapper::FIELD_CREDIT => 'Credit',
    ]);

    expect($rows)->toHaveCount(2);
    expect($rows[0]->amount)->toBe(-450)
        ->and($rows[0]->direction)->toBe(TransactionDirection::Debit);
    expect($rows[1]->amount)->toBe(150000)
        ->and($rows[1]->direction)->toBe(TransactionDirection::Credit);
});

test('currency symbols and thousands separators are stripped', function () {
    $path = tmpCsv("Date,Description,Amount\n01/01/2026,RENT,\"-\$1,250.00\"\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->amount)->toBe(-125000);
});

test('DD/MM/YYYY date format parses', function () {
    $path = tmpCsv("Date,Description,Amount\n31/12/2026,END YEAR,-10.00\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->postDate->format('Y-m-d'))->toBe('2026-12-31');
});

test('ISO YYYY-MM-DD date format parses', function () {
    $path = tmpCsv("Date,Description,Amount\n2026-12-31,END YEAR,-10.00\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->postDate->format('Y-m-d'))->toBe('2026-12-31');
});

test('csv hash is deterministic for same logical row', function () {
    $path = tmpCsv("Date,Description,Amount\n01/01/2026,COFFEE,-4.50\n01/01/2026,COFFEE,-4.50\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows[0]->csvHash)->toBe($rows[1]->csvHash);
});

test('rows missing date are skipped', function () {
    $path = tmpCsv("Date,Description,Amount\n,NO DATE,-4.50\n01/01/2026,GOOD,-1.00\n");

    $parser = new CsvParserService();
    $rows = $parser->preview($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->description)->toBe('GOOD');
});

test('summarize returns row count, date range, totals, and duplicates', function () {
    $fixture = base_path('tests/Fixtures/StatementCsv-Westpac.csv');

    $parser = new CsvParserService();
    $summary = $parser->summarize($fixture, [
        CsvColumnMapper::FIELD_DATE => 'Entered Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Transaction Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
        CsvColumnMapper::FIELD_BALANCE => 'Balance',
    ]);

    expect($summary->rowCount)->toBeGreaterThan(150)
        ->and($summary->earliestDate?->format('Y-m-d'))->toBe('2026-01-01')
        ->and($summary->latestDate?->format('Y-m-d'))->toBe('2026-03-31')
        ->and($summary->totalDebits)->toBeGreaterThan(0)
        ->and($summary->totalCredits)->toBeGreaterThan(0);
});

test('eachRow is a generator that streams rows lazily', function () {
    $path = tmpCsv("Date,Description,Amount\n01/01/2026,A,-1.00\n02/01/2026,B,-2.00\n");

    $parser = new CsvParserService();
    $generator = $parser->eachRow($path, [
        CsvColumnMapper::FIELD_DATE => 'Date',
        CsvColumnMapper::FIELD_DESCRIPTION => 'Description',
        CsvColumnMapper::FIELD_AMOUNT => 'Amount',
    ]);

    expect($generator)->toBeInstanceOf(Generator::class);

    $rows = iterator_to_array($generator, preserve_keys: false);
    expect($rows)->toHaveCount(2);
});
