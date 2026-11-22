<?php

declare(strict_types=1);

use App\Enums\ReportSection;

it('keeps the saved order, ignores unknown and repeated keys and appends missing sections', function () {
    expect(ReportSection::ordered(['trends', 'nonsense', 'trends', 'spending']))->toBe([ReportSection::Trends, ReportSection::Spending, ReportSection::NetWorth])
        ->and(ReportSection::ordered([]))->toBe([ReportSection::Spending, ReportSection::NetWorth, ReportSection::Trends]);
});
