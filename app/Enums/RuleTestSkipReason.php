<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleTestSkipReason: string
{
    case CategorisedByHand = 'categorised_by_hand';
    case FoldNotPossible = 'fold_not_possible';

    public function label(): string
    {
        return match ($this) {
            self::CategorisedByHand => 'Categorised by hand',
            self::FoldNotPossible => 'No matching purchase to fold into',
        };
    }
}
