<?php

declare(strict_types=1);

namespace App\DTOs;

use Spatie\LaravelData\Dto;

/**
 * A row from GET /v1/transactions.
 *
 * `amount` is a pre-signed CDR decimal string: negative is a debit, positive a credit.
 * That already matches this app's convention, so the sign is never inverted. `direction`
 * is carried for audit only — the job derives the stored direction from the sign so the
 * two can never disagree.
 */
final class RedbarkTransactionData extends Dto
{
    public function __construct(
        public readonly string $id = '',
        public readonly ?string $accountId = null,
        public readonly ?string $accountName = null,
        public readonly ?string $status = null,
        public readonly ?string $date = null,
        public readonly ?string $postDate = null,
        public readonly ?string $valueDate = null,
        public readonly ?string $description = null,
        public readonly ?string $amount = null,
        public readonly ?string $direction = null,
        public readonly ?string $category = null,
        public readonly ?string $merchantName = null,
        public readonly ?string $merchantCategoryCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        if (array_key_exists('id', $properties) && $properties['id'] === null) {
            $properties['id'] = '';
        }

        return $properties;
    }
}
