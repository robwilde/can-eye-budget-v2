<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;

final readonly class RuleActionExecutor
{
    /** @param  array<int, array<string, string>>  $actions */
    public function execute(Transaction $transaction, array $actions): void
    {
        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $value = $action['value'] ?? '';

            match ($type) {
                RuleActionType::SetCategory => $this->setCategory($transaction, $value),
                RuleActionType::SetDescription => $transaction->description = $value,
                RuleActionType::AppendNotes => $this->appendNotes($transaction, $value),
                RuleActionType::SetNotes => $transaction->notes = $value,
                RuleActionType::LinkToPlannedTransaction => $this->linkToPlannedTransaction($transaction, $value),
            };
        }

        if ($transaction->isDirty()) {
            $transaction->save();
        }
    }

    private function setCategory(Transaction $transaction, string $value): void
    {
        $categoryId = (int) $value;

        if (Category::visible()->where('id', $categoryId)->exists()) {
            $transaction->category_id = $categoryId;
        }
    }

    private function appendNotes(Transaction $transaction, string $value): void
    {
        if ($transaction->notes === null || $transaction->notes === '') {
            $transaction->notes = $value;

            return;
        }

        $transaction->notes .= "\n".$value;
    }

    private function linkToPlannedTransaction(Transaction $transaction, string $value): void
    {
        $plannedId = (int) $value;

        if (PlannedTransaction::where('id', $plannedId)->where('user_id', $transaction->user_id)->exists()) {
            $transaction->planned_transaction_id = $plannedId;
        }
    }
}
