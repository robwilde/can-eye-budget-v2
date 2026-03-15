<?php

declare(strict_types=1);

arch()->preset()->laravel();
arch()->preset()->security();

arch('services')
    ->expect('App\Services')
    ->toBeFinal();

arch('models do not use floatval')
    ->expect('App\Models')
    ->not->toUse(['floatval']);

arch('dtos')
    ->expect('App\DTOs')
    ->toBeReadonly();

arch('enums are string backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnum();
