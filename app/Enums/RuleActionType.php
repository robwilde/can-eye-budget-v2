<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleActionType: string
{
    case SetCategory = 'set_category';
    case SetDescription = 'set_description';
    case AppendNotes = 'append_notes';
    case SetNotes = 'set_notes';
    case LinkToPlannedTransaction = 'link_to_planned_transaction';

    public function label(): string
    {
        return match ($this) {
            self::SetCategory => 'Set Category',
            self::SetDescription => 'Set Description',
            self::AppendNotes => 'Append Notes',
            self::SetNotes => 'Set Notes',
            self::LinkToPlannedTransaction => 'Link to Planned Transaction',
        };
    }

    public function parameterKey(): string
    {
        return match ($this) {
            self::SetCategory => 'category_id',
            self::SetDescription => 'description',
            self::AppendNotes => 'notes',
            self::SetNotes => 'notes',
            self::LinkToPlannedTransaction => 'planned_transaction_id',
        };
    }
}
