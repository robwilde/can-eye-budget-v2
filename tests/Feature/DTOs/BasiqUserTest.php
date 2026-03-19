<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\BasiqUser;

test('from maps all fields', function () {
    $dto = BasiqUser::from([
        'id' => 'user-123',
        'email' => 'jane@example.com',
        'mobile' => '+61400000000',
    ]);

    expect($dto)
        ->id->toBe('user-123')
        ->email->toBe('jane@example.com')
        ->mobile->toBe('+61400000000');
});

test('from sets mobile to null when missing', function () {
    $dto = BasiqUser::from([
        'id' => 'user-456',
        'email' => 'joe@example.com',
    ]);

    expect($dto)
        ->id->toBe('user-456')
        ->email->toBe('joe@example.com')
        ->mobile->toBeNull();
});
