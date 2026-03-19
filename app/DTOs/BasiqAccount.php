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
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        $properties['type'] = $properties['class']['type'] ?? $properties['type'] ?? null;
        unset($properties['class']);

        return $properties;
    }
}
