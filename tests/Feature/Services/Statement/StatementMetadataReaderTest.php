<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementFileType;
use App\Services\Statement\StatementMetadataReader;
use Tests\Feature\Services\Statement\StatementPdfFactory;

function readStatementPdf(array $lines, string $filename = 'statement.pdf', array $secondPage = [], array $thirdPage = []): App\DTOs\StatementMetadata
{
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, StatementPdfFactory::make($lines, $secondPage, $thirdPage));

    try {
        return app(StatementMetadataReader::class)->read($path, StatementFileType::Pdf, $filename);
    } finally {
        unlink($path);
    }
}

it('reads period, balances, currency and last four from an english pdf', function () {
    $metadata = readStatementPdf([
        'Example Bank',
        'Account number: 1234 5678 9012',
        'Statement period: 1 March 2026 to 31 March 2026',
        'Currency: EUR',
        'Opening balance 1,000.00',
        'Total credits 500.00',
        'Total debits 200.00',
        'Closing balance 1,300.00',
    ]);

    expect($metadata->periodStart?->toDateString())->toBe('2026-03-01')
        ->and($metadata->periodEnd?->toDateString())->toBe('2026-03-31')
        ->and($metadata->openingBalance)->toBe(100000)
        ->and($metadata->closingBalance)->toBe(130000)
        ->and($metadata->currency)->toBe('EUR')
        ->and($metadata->lastFour)->toBe('9012');
});

it('understands german, french, spanish and dutch labels', function (array $lines, string $start, string $end, int $opening, int $closing) {
    $metadata = readStatementPdf($lines);

    expect($metadata->periodStart?->toDateString())->toBe($start)
        ->and($metadata->periodEnd?->toDateString())->toBe($end)
        ->and($metadata->openingBalance)->toBe($opening)
        ->and($metadata->closingBalance)->toBe($closing);
})->with([
    'german' => [['Kontoauszug Zeitraum 01.03.2026 bis 31.03.2026', 'Anfangssaldo 1.234,56', 'Endsaldo 2.000,00'], '2026-03-01', '2026-03-31', 123456, 200000],
    'french' => [['Relevé période du 1 mars 2026 au 31 mars 2026', 'Solde initial 1 234,56', 'Nouveau solde 2 000,00'], '2026-03-01', '2026-03-31', 123456, 200000],
    'spanish' => [['Extracto del 01/03/2026 al 31/03/2026', 'Saldo anterior 1.234,56', 'Saldo final 2.000,00'], '2026-03-01', '2026-03-31', 123456, 200000],
    'dutch' => [['Afschrift 01-03-2026 tot 31-03-2026', 'Beginsaldo 1.234,56', 'Eindsaldo 2.000,00'], '2026-03-01', '2026-03-31', 123456, 200000],
]);

it('reads a negative closing balance', function () {
    $metadata = readStatementPdf(['Opening balance 100.00', 'Closing balance -20.00']);

    expect($metadata->openingBalance)->toBe(10000)
        ->and($metadata->closingBalance)->toBe(-2000);
});

it('drops balances that do not add up', function () {
    $metadata = readStatementPdf([
        'Opening balance 1,000.00',
        'Total credits 500.00',
        'Total debits 200.00',
        'Closing balance 9,999.00',
    ]);

    expect($metadata->openingBalance)->toBeNull()
        ->and($metadata->closingBalance)->toBeNull();
});

it('drops balances that mix currencies', function () {
    $metadata = readStatementPdf(['Opening balance EUR 1,000.00', 'Closing balance GBP 1,300.00']);

    expect($metadata->openingBalance)->toBeNull()
        ->and($metadata->closingBalance)->toBeNull()
        ->and($metadata->currency)->toBeNull();
});

it('reads the second page but not the third', function () {
    $second = readStatementPdf(['Page one'], 'statement.pdf', ['Closing balance 10.00']);
    $third = readStatementPdf(['Page one'], 'statement.pdf', ['Page two'], ['Closing balance 10.00']);

    expect($second->closingBalance)->toBe(1000)
        ->and($third->closingBalance)->toBeNull();
});

it('falls back to the filename for period, last four and institution', function () {
    $metadata = readStatementPdf(['Nothing useful here'], 'CommBank_statement_xxxx4321_2026-03.pdf');

    expect($metadata->institution)->toBe('CommBank')
        ->and($metadata->lastFour)->toBe('4321')
        ->and($metadata->periodStart?->toDateString())->toBe('2026-03-01')
        ->and($metadata->periodEnd?->toDateString())->toBe('2026-03-31');
});

it('prefers the pdf text over the filename', function () {
    $metadata = readStatementPdf(['Statement period: 1 February 2026 to 28 February 2026'], 'bank_2026-03.pdf');

    expect($metadata->periodStart?->toDateString())->toBe('2026-02-01');
});

it('reads a month name and year from the filename', function () {
    $metadata = readStatementPdf(['x'], 'ANZ March 2026.pdf');

    expect($metadata->periodStart?->toDateString())->toBe('2026-03-01')
        ->and($metadata->institution)->toBe('ANZ');
});

it('reads only the filename for a spreadsheet', function () {
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, 'not read');

    try {
        $metadata = app(StatementMetadataReader::class)->read($path, StatementFileType::Xlsx, 'westpac_2026-02.xlsx');
    } finally {
        unlink($path);
    }

    expect($metadata->institution)->toBe('westpac')
        ->and($metadata->periodStart?->toDateString())->toBe('2026-02-01')
        ->and($metadata->openingBalance)->toBeNull();
});

it('returns only filename data for a pdf it cannot parse', function () {
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, "%PDF-1.4\ngarbage");

    try {
        $metadata = app(StatementMetadataReader::class)->read($path, StatementFileType::Pdf, 'bank_2026-01.pdf');
    } finally {
        unlink($path);
    }

    expect($metadata->openingBalance)->toBeNull()
        ->and($metadata->periodStart?->toDateString())->toBe('2026-01-01');
});

it('survives a pdf that inflates far beyond the decode limit', function () {
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, StatementPdfFactory::inflationBomb(300));

    try {
        $metadata = app(StatementMetadataReader::class)->read($path, StatementFileType::Pdf, 'bank_2026-01.pdf');
    } finally {
        unlink($path);
    }

    expect($metadata->openingBalance)->toBeNull()
        ->and($metadata->periodStart?->toDateString())->toBe('2026-01-01');
});

it('falls back to the filename when a pdf font encoding cannot be read', function () {
    $pdf = str_replace('/Encoding /WinAnsiEncoding', '/Encoding << /BaseEncoding /CustomBankEncoding >>', StatementPdfFactory::make(['Closing balance 10.00']));
    $path = tempnam(sys_get_temp_dir(), 'stmt');
    file_put_contents($path, $pdf);

    try {
        $metadata = app(StatementMetadataReader::class)->read($path, StatementFileType::Pdf, 'bank_2026-01.pdf');
    } finally {
        unlink($path);
    }

    expect($metadata->periodStart?->toDateString())->toBe('2026-01-01');
});

it('reads each balance from its own label when labels are listed before their amounts', function (array $lines, ?int $opening, ?int $closing) {
    $metadata = readStatementPdf($lines);

    expect($metadata->openingBalance)->toBe($opening)
        ->and($metadata->closingBalance)->toBe($closing);
})->with([
    'labels then a row of amounts' => [['Opening balance Total credits Total debits Closing balance', '$1,000.00 $500.00 $200.00 $1,300.00'], null, null],
    'date between label and amount is left unread' => [['Opening balance on 1 March 2026 $1,000.00', 'Closing balance on 31 March 2026 $1,300.00'], null, null],
    'german date between label and amount is left unread' => [['Anfangssaldo am 01.03.2026 1.234,56', 'Endsaldo am 31.03.2026 2.000,00'], null, null],
    'amount on the line after a lone label' => [['Opening balance', '1,000.00', 'Closing balance', '1,300.00'], 100000, 130000],
    'trailing sentence punctuation' => [['Your opening balance was 1,000.00, closing balance 1,300.00.'], 100000, 130000],
]);

it('does not take a year or a balance as the last four', function (array $lines, string $filename, ?string $expected) {
    expect(readStatementPdf($lines, $filename)->lastFour)->toBe($expected);
})->with([
    'statement year' => [['Account Statement March 2026'], 'x.pdf', null],
    'german statement year' => [['Kontoauszug März 2026'], 'x.pdf', null],
    'balance amount' => [['Account balance 1234.56'], 'x.pdf', null],
    'filename wins over a year in the text' => [['Account Statement March 2026'], 'CommBank_xxxx4321_2026-03.pdf', '4321'],
    'card ending in' => [['Card ending in 1234'], 'x.pdf', '1234'],
    'number then a date on the next line' => [['Account number 12345678', '01/03/2026 Opening'], 'x.pdf', '5678'],
    'year in the filename' => [['x'], 'Westpac_Account_2026-03.pdf', null],
]);

it('reads abbreviated french and spanish months', function (string $line, string $start, string $end) {
    $metadata = readStatementPdf([$line]);

    expect($metadata->periodStart?->toDateString())->toBe($start)
        ->and($metadata->periodEnd?->toDateString())->toBe($end);
})->with([
    'french décembre' => ['du 1 déc. 2025 au 31 déc. 2025', '2025-12-01', '2025-12-31'],
    'french janvier' => ['du 1 janv. 2026 au 31 janv. 2026', '2026-01-01', '2026-01-31'],
    'spanish enero' => ['del 1 ene 2026 al 31 ene 2026', '2026-01-01', '2026-01-31'],
]);

it('ignores an implausible year in the filename', function () {
    expect(readStatementPdf(['x'], 'Statement_4321_06.pdf')->periodStart)->toBeNull();
});

it('treats the unicode minus sign as negative', function () {
    $amount = new ReflectionMethod(StatementMetadataReader::class, 'amountAfter');

    $hit = $amount->invoke(app(StatementMetadataReader::class), "Closing balance \u{2212}20.00", ['closing balance']);

    expect($hit['cents'])->toBe(-2000);
});
