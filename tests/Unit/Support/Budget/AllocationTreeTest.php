<?php

declare(strict_types=1);

use App\Support\Budget\AllocationTree;

function allocationTree(array $limits): AllocationTree
{
    return new AllocationTree([1 => null, 2 => 1, 3 => 2, 4 => 3, 9 => null], $limits);
}

it('finds numeric ancestors along the whole chain and skips Shared and missing lines', function () {
    $tree = allocationTree([1 => 6000, 2 => null, 4 => 6000]);

    expect($tree->numericAncestors(4))->toBe([1])
        ->and($tree->numericAncestors(1))->toBe([])
        ->and($tree->isCovered(4))->toBeTrue()
        ->and($tree->isCovered(1))->toBeFalse();
});

it('lists every numeric ancestor nearest first', function () {
    expect(allocationTree([1 => 9000, 2 => 7000, 3 => null, 4 => 5000])->numericAncestors(4))->toBe([2, 1]);
});

it('holds the nearest numeric lines below a category through Shared and missing intermediates', function () {
    expect(allocationTree([1 => 6000, 2 => null, 4 => 5000])->held(1))->toBe(5000)
        ->and(allocationTree([1 => 6000, 2 => 5000, 4 => 5000])->held(1))->toBe(5000)
        ->and(allocationTree([1 => 6000, 3 => 2000, 4 => 1000])->held(1))->toBe(2000)
        ->and(allocationTree([1 => 6000])->held(1))->toBe(0);
});

it('gives a Shared or missing line what is dedicated below it as its inclusive allocation', function () {
    $tree = allocationTree([1 => 6000, 2 => null, 4 => 5000]);

    expect($tree->inclusive(2))->toBe(5000)
        ->and($tree->inclusive(3))->toBe(5000)
        ->and($tree->inclusive(1))->toBe(6000);
});

it('totals only the allocations no numeric line covers', function () {
    expect(allocationTree([1 => 6000, 4 => 6000, 9 => 1000])->allocatedTotal())->toBe(7000)
        ->and(allocationTree([2 => null, 4 => 3000])->allocatedTotal())->toBe(3000)
        ->and(allocationTree([])->allocatedTotal())->toBe(0);
});

it('survives a parent cycle', function () {
    $tree = new AllocationTree([1 => 2, 2 => 1], [1 => 100, 2 => 200]);

    expect($tree->numericAncestors(1))->toBe([2])
        ->and($tree->held(1))->toBe(200);
});
