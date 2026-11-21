<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleActionType: string
{
    case SetCategory = 'set_category';
    case SetDescription = 'set_description';
    case SetCleanDescription = 'set_clean_description';
    case AppendNotes = 'append_notes';
    case SetNotes = 'set_notes';
    case LinkToPlannedTransaction = 'link_to_planned_transaction';
    case FoldIntoParent = 'fold_into_parent';
    case ExcludeFromReports = 'exclude_from_reports';
    case MarkAsTransfer = 'mark_as_transfer';
    case EmailDigest = 'email_digest';
    case AddTags = 'add_tags';
    case SuggestCategoryWithAi = 'suggest_category_with_ai';

    public function label(): string
    {
        return match ($this) {
            self::SetCategory => 'Set Category',
            self::SetDescription => 'Set Description',
            self::SetCleanDescription => 'Set Clean Description',
            self::AppendNotes => 'Append Notes',
            self::SetNotes => 'Set Notes',
            self::LinkToPlannedTransaction => 'Link to Planned Transaction',
            self::FoldIntoParent => 'Fold into parent transaction',
            self::ExcludeFromReports => 'Exclude from budgets and reports',
            self::MarkAsTransfer => 'Mark as Transfer',
            self::EmailDigest => 'Email me new matches',
            self::AddTags => 'Add Tags',
            self::SuggestCategoryWithAi => 'Categorise with AI (Jev)',
        };
    }

    public function parameterKey(): string
    {
        return match ($this) {
            self::SetCategory => 'category_id',
            self::SetDescription => 'description',
            self::SetCleanDescription => 'clean_description',
            self::AppendNotes => 'notes',
            self::SetNotes => 'notes',
            self::LinkToPlannedTransaction => 'planned_transaction_id',
            self::FoldIntoParent, self::ExcludeFromReports, self::EmailDigest, self::SuggestCategoryWithAi => '',
            self::AddTags => 'tag_ids',
            self::MarkAsTransfer => 'account_id',
        };
    }

    public function requiresValue(): bool
    {
        return ! in_array($this, [self::FoldIntoParent, self::ExcludeFromReports, self::EmailDigest, self::AddTags, self::SuggestCategoryWithAi], true);
    }
}
