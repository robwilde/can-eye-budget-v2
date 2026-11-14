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
    ->toExtend(Spatie\LaravelData\Dto::class)
    ->toBeFinal();

arch('enums are string backed')
    ->expect('App\Enums')
    ->toBeStringBackedEnum();

arch('insight code keeps the DB facade out')
    ->expect([
        App\Services\InsightRecorder::class,
        'App\Services\InsightGenerators',
        'App\Support\Insights',
        App\Jobs\GenerateInsightsJob::class,
        App\Console\Commands\GenerateInsightsCommand::class,
        App\Models\Insight::class,
    ])
    ->not->toUse(Illuminate\Support\Facades\DB::class);
