<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * The subset of a Context.dev brand profile a budgeting UI needs to label a transaction.
 *
 * Upstream fields are independently optional; `title` falls back to the domain when the
 * profile has no name. `partial` is true when the request deadline cut the profile
 * short; do not cache such a record as complete.
 */
final class MerchantBrandData extends Dto
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $domain = null,
        public readonly ?string $logoUrl = null,
        public readonly ?string $industry = null,
        public readonly ?string $subindustry = null,
        public readonly bool $partial = false,
    ) {}
}
