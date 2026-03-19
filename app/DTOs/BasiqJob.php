<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Dto;

final class BasiqJob extends Dto
{
    #[Computed]
    public readonly string $status;

    /** @param  array<int, array{title: string, status: string, result?: array{type?: string, url?: string}}>  $steps */
    public function __construct(
        public readonly string $id,
        public readonly array $steps,
    ) {
        $this->status = self::resolveStatus($steps);
    }

    /** @param  array<int, array{title: string, status: string}>  $steps */
    private static function resolveStatus(array $steps): string
    {
        if (array_any($steps, fn ($step) => $step['status'] === 'failed')) {
            return 'failed';
        }

        if (array_any($steps, fn ($step) => $step['status'] !== 'success')) {
            return 'pending';
        }

        return 'success';
    }
}
