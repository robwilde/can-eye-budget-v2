<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class BasiqUser extends Dto
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly ?string $mobile = null,
    ) {}
}
