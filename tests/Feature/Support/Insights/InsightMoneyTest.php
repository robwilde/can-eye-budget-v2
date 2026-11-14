<?php

declare(strict_types=1);

use App\Support\Insights\InsightMoney;

it('keeps the plain dollar sign for AUD and names every other currency', function () {
    expect(InsightMoney::format(123456, 'AUD'))->toBe('$1,234.56')
        ->and(InsightMoney::format(30000, 'eur'))->toBe('EUR 300.00')
        ->and(InsightMoney::format(-10000, 'USD'))->toBe('-USD 100.00');
});
