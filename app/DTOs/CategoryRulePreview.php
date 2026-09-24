<?php

declare(strict_types=1);

namespace App\DTOs;

use Livewire\Wireable;
use Spatie\LaravelData\Dto;

/**
 * What creating a categorisation rule would actually do, computed before the
 * rule exists.
 *
 * A generated rule is created with is_auto_apply and swept across the user's
 * whole history, so its reach is unbounded — unlike applying a category to a
 * selection. This is the number the user gets to see before committing to that.
 *
 * Wireable so TransactionList can hold it between requests: computing it walks
 * the user's whole history, so it is recomputed only when an input changes,
 * not on every render.
 */
final class CategoryRulePreview extends Dto implements Wireable
{
    /**
     * @param  string  $matchValue  the `description contains` value the rule will carry
     * @param  int  $inSelection  matching rows the user already had selected
     * @param  int  $beyondSelection  matching rows outside the selection — the surprise factor
     * @param  int  $wouldChange  rows that will actually be written
     * @param  int  $protectedByManual  rows skipped because a human set their category
     * @param  array<string, int>  $existingCategories  category full path => count, for rows outside the selection that already have one
     */
    public function __construct(
        public readonly string $matchValue,
        public readonly int $inSelection,
        public readonly int $beyondSelection,
        public readonly int $wouldChange,
        public readonly int $protectedByManual,
        public readonly array $existingCategories = [],
    ) {}

    /** @param  array<string, mixed>  $value */
    public static function fromLivewire($value): self
    {
        return new self(
            matchValue: (string) $value['matchValue'],
            inSelection: (int) $value['inSelection'],
            beyondSelection: (int) $value['beyondSelection'],
            wouldChange: (int) $value['wouldChange'],
            protectedByManual: (int) $value['protectedByManual'],
            existingCategories: array_map(intval(...), (array) $value['existingCategories']),
        );
    }

    public function totalMatches(): int
    {
        return $this->inSelection + $this->beyondSelection;
    }

    /** @return array<string, mixed> */
    public function toLivewire(): array
    {
        return [
            'matchValue' => $this->matchValue,
            'inSelection' => $this->inSelection,
            'beyondSelection' => $this->beyondSelection,
            'wouldChange' => $this->wouldChange,
            'protectedByManual' => $this->protectedByManual,
            'existingCategories' => $this->existingCategories,
        ];
    }
}
