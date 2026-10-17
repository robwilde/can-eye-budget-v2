<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\RuleTestSkipReason;
use App\Enums\TransactionDirection;
use Livewire\Wireable;
use Spatie\LaravelData\Dto;

/**
 * One transaction a draft rule matched, as the rule editor lists it.
 *
 * Wireable so the result can sit on the Livewire component between requests.
 */
final class RuleTestRow extends Dto implements Wireable
{
    /**
     * @param  int  $amount  absolute amount in cents
     * @param  bool  $categoryKept  the rule sets a category but a person's own is kept
     */
    public function __construct(
        public readonly int $id,
        public readonly string $postDate,
        public readonly string $name,
        public readonly int $amount,
        public readonly TransactionDirection $direction,
        public readonly string $accountName,
        public readonly ?string $categoryPath,
        public readonly bool $categoryKept = false,
        public readonly ?RuleTestSkipReason $skipReason = null,
    ) {}

    /** @param  array<string, mixed>  $value */
    public static function fromLivewire($value): self
    {
        return new self(
            id: (int) $value['id'],
            postDate: (string) $value['postDate'],
            name: (string) $value['name'],
            amount: (int) $value['amount'],
            direction: TransactionDirection::from((string) $value['direction']),
            accountName: (string) $value['accountName'],
            categoryPath: $value['categoryPath'] === null ? null : (string) $value['categoryPath'],
            categoryKept: (bool) $value['categoryKept'],
            skipReason: $value['skipReason'] === null ? null : RuleTestSkipReason::from((string) $value['skipReason']),
        );
    }

    /** @return array<string, mixed> */
    public function toLivewire(): array
    {
        return [
            'id' => $this->id,
            'postDate' => $this->postDate,
            'name' => $this->name,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'accountName' => $this->accountName,
            'categoryPath' => $this->categoryPath,
            'categoryKept' => $this->categoryKept,
            'skipReason' => $this->skipReason?->value,
        ];
    }
}
