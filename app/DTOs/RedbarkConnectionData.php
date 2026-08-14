<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * A row from GET /v1/connections. `category` gates which accounts may be sent to
 * /transactions and /balances at all.
 */
final class RedbarkConnectionData extends Dto
{
    public function __construct(
        public readonly string $id = '',
        public readonly ?string $category = null,
        public readonly ?string $institutionName = null,
        public readonly ?string $institutionLogo = null,
        public readonly ?string $status = null,
    ) {}
}
