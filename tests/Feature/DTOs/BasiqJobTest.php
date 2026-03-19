<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BasiqJob;

test('resolveStatus returns success when all steps succeed', function () {
    $dto = BasiqJob::from([
        'id' => 'job-1',
        'steps' => [
            ['title' => 'verify-credentials', 'status' => 'success'],
            ['title' => 'retrieve-accounts', 'status' => 'success'],
            ['title' => 'retrieve-transactions', 'status' => 'success'],
        ],
    ]);

    expect($dto)
        ->id->toBe('job-1')
        ->status->toBe('success')
        ->steps->toHaveCount(3);
});

test('resolveStatus returns failed when any step fails', function () {
    $dto = BasiqJob::from([
        'id' => 'job-2',
        'steps' => [
            ['title' => 'verify-credentials', 'status' => 'success'],
            ['title' => 'retrieve-accounts', 'status' => 'failed'],
            ['title' => 'retrieve-transactions', 'status' => 'pending'],
        ],
    ]);

    expect($dto->status)->toBe('failed');
});

test('resolveStatus returns pending when steps are in progress', function () {
    $dto = BasiqJob::from([
        'id' => 'job-3',
        'steps' => [
            ['title' => 'verify-credentials', 'status' => 'success'],
            ['title' => 'retrieve-accounts', 'status' => 'pending'],
            ['title' => 'retrieve-transactions', 'status' => 'pending'],
        ],
    ]);

    expect($dto->status)->toBe('pending');
});

test('from preserves step result data', function () {
    $steps = [
        [
            'title' => 'verify-credentials',
            'status' => 'success',
            'result' => ['type' => 'link', 'url' => '/users/abc/accounts'],
        ],
    ];

    $dto = BasiqJob::from([
        'id' => 'job-4',
        'steps' => $steps,
    ]);

    expect($dto->steps)->toBe($steps);
});
