<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Support\RedbarkCurrency;
use Spatie\LaravelData\Dto;

/**
 * A row from GET /v1/accounts. The Data suffix keeps this distinct from the
 * App\Models\RedbarkAccount it eventually populates.
 *
 * Amounts and dates stay as strings across every Redbark DTO: they are a faithful
 * record of the wire payload, and conversion to cents and CarbonImmutable happens in
 * SyncRedbarkFeedJob. Every property defaults, so a row that omits a field still binds
 * and is filtered by the job's own blank checks rather than blowing up the whole page.
 */
final class RedbarkAccountData extends Dto
{
    public function __construct(
        public readonly string $id = '',
        public readonly ?string $connectionId = null,
        public readonly ?string $provider = null,
        public readonly string $name = '',
        public readonly ?string $type = null,
        public readonly ?string $institutionName = null,
        public readonly ?string $accountNumber = null,
        public readonly ?string $currency = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['currency'] = RedbarkCurrency::normalise($properties['currency'] ?? null);

        return $properties;
    }
}
