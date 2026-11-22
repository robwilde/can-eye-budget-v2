<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Services\CsvImport\QifParserService;

function qifFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'qif-');
    file_put_contents($path, $content);

    return $path;
}

it('reads bank, credit-card and cash sections and skips the rest', function () {
    $path = qifFile(<<<'QIF'
        !Option:AutoSwitch
        !Account
        NSavings
        TBank
        ^
        !Type:Bank
        D15/03/2024
        T-4.50
        PCoffee
        ^
        !Type:CCard
        D16/03/2024
        T-20.00
        PShop
        ^
        !Type:Cash
        D17/03/2024
        T-5.00
        PKiosk
        ^
        !Type:Cat
        NFood
        ^
        !Type:Invst
        D18/03/2024
        NBuy
        ^
        QIF);

    $rows = iterator_to_array(app(QifParserService::class)->rows($path), false);

    expect(array_column($rows, 'description'))->toBe(['Coffee', 'Shop', 'Kiosk'])
        ->and(array_column($rows, 'amount'))->toBe([-450, -2000, -500]);
});

it('reads signed amounts with thousands separators and currency symbols', function () {
    $path = qifFile("!Type:Bank\nD01/03/2024\nT-1,234.56\nPOne\n^\nD02/03/2024\nT\$2,000.00\nPTwo\n^\nD03/03/2024\nUUnused\nT7\nPThree\n^\n");

    $parsed = app(QifParserService::class)->parse($path);

    expect(array_map(fn ($row) => [$row->amount, $row->direction], $parsed))->toBe([
        [-123456, TransactionDirection::Debit],
        [200000, TransactionDirection::Credit],
        [700, TransactionDirection::Credit],
    ]);
});

it('splits a category from its class and ignores transfers', function () {
    $path = qifFile("!Type:Bank\nD01/03/2024\nT-1.00\nPOne\nLFood:Groceries/Home\n^\nD02/03/2024\nT-2.00\nPTwo\nL[Savings]\n^\nD03/03/2024\nT-3.00\nPThree\n^\n");

    $parsed = app(QifParserService::class)->parse($path);

    expect(array_map(fn ($row) => [$row->category, $row->tag], $parsed))->toBe([['Food:Groceries', 'Home'], [null, null], [null, null]]);
});

it('falls back to the memo when there is no payee', function () {
    $parsed = app(QifParserService::class)->parse(qifFile("!Type:Bank\nD01/03/2024\nT-1.00\nMJust a memo\n^\n"));

    expect($parsed[0]->description)->toBe('Just a memo');
});

it('detects the date order from a date that settles it', function () {
    $parser = app(QifParserService::class);

    expect($parser->detectOrder(['31/01/2024', '02/03/2024']))->toBe(QifParserService::ORDER_DAY_FIRST)
        ->and($parser->detectOrder(['01/31/2024', '02/03/2024']))->toBe(QifParserService::ORDER_MONTH_FIRST)
        ->and($parser->detectOrder(['01/02/2024', '02/03/2024']))->toBeNull();
});

it('reads Quicken apostrophe years and padded dates', function () {
    $parsed = app(QifParserService::class)->parse(qifFile("!Type:Bank\nD 1/ 5'19\nT-1.00\nPOne\n^\nD12/31'20\nT-1.00\nPTwo\n^\n"), QifParserService::ORDER_MONTH_FIRST);

    expect($parsed[0]->postDate->toDateString())->toBe('2019-01-05')
        ->and($parsed[1]->postDate->toDateString())->toBe('2020-12-31');
});

it('honours a chosen date order and returns an unreadable record with the cell that failed', function () {
    $path = qifFile("!Type:Bank\nD01/02/2024\nT-1.00\nPOne\n^\nD31/02/2024\nT-1.00\nPTwo\n^\nD03/02/2024\nTabc\nPThree\n^\n");
    $parser = app(QifParserService::class);

    $dayFirst = iterator_to_array($parser->rows($path, QifParserService::ORDER_DAY_FIRST), false);
    $monthFirst = iterator_to_array($parser->rows($path, QifParserService::ORDER_MONTH_FIRST), false);

    expect($dayFirst[0]['date']->toDateString())->toBe('2024-02-01')
        ->and($monthFirst[0]['date']->toDateString())->toBe('2024-01-02')
        ->and(array_keys($dayFirst[1]['errors']))->toBe(['date'])
        ->and(array_keys($dayFirst[2]['errors']))->toBe(['amount'])
        ->and($parser->parse($path, QifParserService::ORDER_DAY_FIRST))->toHaveCount(1);
});

it('rejects a future date', function () {
    $tomorrow = now()->addDay()->format('d/m/Y');
    $rows = iterator_to_array(app(QifParserService::class)->rows(qifFile("!Type:Bank\nD{$tomorrow}\nT-1.00\nPOne\n^\n"), QifParserService::ORDER_DAY_FIRST), false);

    expect($rows[0]['errors']['date'])->toContain('future');
});

it('converts a Windows-1252 file to UTF-8', function () {
    $content = mb_convert_encoding("!Type:Bank\nD01/03/2024\nT-1.00\nPCafé\n^\n", 'Windows-1252', 'UTF-8');

    expect(app(QifParserService::class)->parse(qifFile($content))[0]->description)->toBe('Café');
});

it('refuses a file that is not a QIF file or has no transactions', function () {
    $parser = app(QifParserService::class);

    expect(fn () => iterator_to_array($parser->rows(qifFile("Date,Amount\n1,2\n")), false))->toThrow(RuntimeException::class)
        ->and(fn () => iterator_to_array($parser->rows(qifFile("!Type:Cat\nNFood\n^\n")), false))->toThrow(RuntimeException::class);
});

it('keeps identical records apart with their own hashes', function () {
    $record = "D01/03/2024\nT-1.00\nPSame\n^\n";
    $parsed = app(QifParserService::class)->parse(qifFile("!Type:Bank\n{$record}{$record}"));

    expect($parsed)->toHaveCount(2)
        ->and($parsed[0]->csvHash)->not->toBe($parsed[1]->csvHash);
});

it('reads Quicken\'s single-digit apostrophe years and lets them settle the date order', function () {
    $parser = app(QifParserService::class);
    $path = qifFile("!Type:Bank\nD12/25' 9\nT-1.00\nPOne\n^\nD 1/ 5'19\nT-1.00\nPTwo\n^\n");

    $rows = iterator_to_array($parser->rows($path), false);

    expect($rows[0]['date']->toDateString())->toBe('2009-12-25')
        ->and($rows[1]['date']->toDateString())->toBe('2019-01-05');
});

it('reads a file that starts with a UTF-8 byte order mark and holds Windows-1252 text', function () {
    $content = "\xEF\xBB\xBF!Type:Bank\nD01/03/2024\nT-1.00\nPCaf\xE9\n^\n";

    expect(app(QifParserService::class)->parse(qifFile($content))[0]->description)->toBe('Café');
});

it('refuses a file that holds more than one account', function () {
    $path = qifFile("!Option:AutoSwitch\n!Account\nNChecking\nTBank\n^\n!Type:Bank\nD01/03/2024\nT-1.00\nPChk\n^\n!Account\nNSavings\nTBank\n^\n!Type:Bank\nD01/03/2024\nT5.00\nPSav\n^\n");

    expect(fn () => iterator_to_array(app(QifParserService::class)->rows($path), false))->toThrow(RuntimeException::class, 'more than one account');
});

it('keeps the sections of one account together', function () {
    $path = qifFile("!Account\nNChecking\nTBank\n^\n!Type:Bank\nD01/03/2024\nT-1.00\nPOne\n^\n!Type:Cash\nD02/03/2024\nT-2.00\nPTwo\n^\n");

    expect(app(QifParserService::class)->parse($path))->toHaveCount(2);
});
