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

test('failedSteps returns only failed steps with their result info', function () {
    $dto = BasiqJob::from([
        'id' => 'job-5',
        'steps' => [
            ['title' => 'verify-credentials', 'status' => 'success'],
            [
                'title' => 'retrieve-accounts',
                'status' => 'failed',
                'result' => ['type' => 'institution-unavailable', 'url' => '/errors/abc'],
            ],
            ['title' => 'retrieve-transactions', 'status' => 'failed'],
        ],
    ]);

    expect($dto->failedSteps())->toBe([
        [
            'title' => 'retrieve-accounts',
            'error_type' => 'institution-unavailable',
            'error_url' => '/errors/abc',
        ],
        [
            'title' => 'retrieve-transactions',
            'error_type' => null,
            'error_url' => null,
        ],
    ]);
});

test('failedSteps returns empty array when no steps fail', function () {
    $dto = BasiqJob::from([
        'id' => 'job-6',
        'steps' => [['title' => 'verify-credentials', 'status' => 'success']],
    ]);

    expect($dto->failedSteps())->toBe([]);
});
