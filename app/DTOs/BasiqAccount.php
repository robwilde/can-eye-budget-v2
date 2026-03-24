<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

final class BasiqAccount extends Dto
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $institution,
        public readonly ?string $type,
        public readonly ?string $balance,
        public readonly string $currency,
        public readonly ?string $status = null,
        public readonly ?string $creditLimit = null,
        public readonly ?string $availableFunds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['type'] = $properties['class']['type'] ?? $properties['type'] ?? null;
        unset($properties['class']);

        $properties['creditLimit'] = self::emptyToNull($properties['creditLimit'] ?? null);
        $properties['availableFunds'] = self::emptyToNull($properties['availableFunds'] ?? null);

        return $properties;
    }

    private static function emptyToNull(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }
}
