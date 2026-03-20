<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class BasiqTransaction extends Dto
{
    /** @param  ?array<string, mixed>  $enrichData */
    public function __construct(
        public readonly string $id,
        public readonly string $amount,
        public readonly string $direction,
        public readonly ?string $description = null,
        public readonly ?string $postDate = null,
        public readonly ?string $account = null,
        public readonly ?string $status = null,
        public readonly ?string $transactionDate = null,
        public readonly ?string $merchant = null,
        public readonly ?string $anzsic = null,
        public readonly ?array $enrichData = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $enrich = $properties['enrich'] ?? null;
        $properties['merchant'] = $enrich['merchant']['businessName'] ?? null;
        $properties['anzsic'] = $enrich['category']['anzsic']['code'] ?? null;
        $properties['enrichData'] = $enrich;
        unset($properties['enrich']);

        return $properties;
    }
}
