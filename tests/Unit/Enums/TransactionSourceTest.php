<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionSource;

test('all transaction source cases exist', function () {
    expect(TransactionSource::cases())->toHaveCount(5);
});

test('transaction source has correct backing values', function () {
    expect(TransactionSource::Manual->value)->toBe('manual')
        ->and(TransactionSource::Planned->value)->toBe('planned')
        ->and(TransactionSource::Csv->value)->toBe('csv')
        ->and(TransactionSource::Redbark->value)->toBe('redbark')
        ->and(TransactionSource::Pdf->value)->toBe('pdf');
});

test('transaction source resolves from backing value', function () {
    expect(TransactionSource::from('manual'))->toBe(TransactionSource::Manual)
        ->and(TransactionSource::from('planned'))->toBe(TransactionSource::Planned)
        ->and(TransactionSource::from('csv'))->toBe(TransactionSource::Csv)
        ->and(TransactionSource::from('redbark'))->toBe(TransactionSource::Redbark)
        ->and(TransactionSource::from('pdf'))->toBe(TransactionSource::Pdf);
});

test('transaction source has labels', function () {
    expect(TransactionSource::Manual->label())->toBe('Manual')
        ->and(TransactionSource::Planned->label())->toBe('Planned')
        ->and(TransactionSource::Csv->label())->toBe('CSV import')
        ->and(TransactionSource::Redbark->label())->toBe('Redbark')
        ->and(TransactionSource::Pdf->label())->toBe('PDF import');
});

test('bank statement sources are the ones analysis runs on', function () {
    expect(TransactionSource::forAnalysis())
        ->toBe([TransactionSource::Csv, TransactionSource::Redbark, TransactionSource::Pdf]);
});

test('a pdf import row is not a bank-feed row', function () {
    expect(TransactionSource::Pdf->isBankFeed())->toBeFalse()
        ->and(TransactionSource::bankFeed())->toBe([TransactionSource::Redbark]);
});
