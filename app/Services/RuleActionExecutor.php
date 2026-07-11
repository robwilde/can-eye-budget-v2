<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;

final readonly class RuleActionExecutor
{
    public function __construct(private TransactionFeeFolder $feeFolder) {}

    /**
     * @param  array<int, array<string, string>>  $actions
     * @return bool Whether any action took effect.
     */
    public function execute(Transaction $transaction, array $actions): bool
    {
        $applied = false;

        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $value = $action['value'] ?? '';

            $applied = match ($type) {
                RuleActionType::SetCategory => $this->setCategory($transaction, $value),
                RuleActionType::SetDescription => $this->setDescription($transaction, $value),
                RuleActionType::AppendNotes => $this->appendNotes($transaction, $value),
                RuleActionType::SetNotes => $this->setNotes($transaction, $value),
                RuleActionType::LinkToPlannedTransaction => $this->linkToPlannedTransaction($transaction, $value),
                RuleActionType::FoldIntoParent => $this->feeFolder->fold($transaction) instanceof Transaction,
            } || $applied;
        }

        if ($transaction->isDirty()) {
            $transaction->save();
        }

        return $applied;
    }

    private function setCategory(Transaction $transaction, string $value): bool
    {
        $categoryId = (int) $value;

        if (Category::visible()->where('id', $categoryId)->exists()) {
            $transaction->category_id = $categoryId;
        }

        return true;
    }

    private function setDescription(Transaction $transaction, string $value): bool
    {
        $transaction->description = $value;

        return true;
    }

    private function appendNotes(Transaction $transaction, string $value): bool
    {
        if ($transaction->notes === null || $transaction->notes === '') {
            $transaction->notes = $value;

            return true;
        }

        $transaction->notes .= "\n".$value;

        return true;
    }

    private function setNotes(Transaction $transaction, string $value): bool
    {
        $transaction->notes = $value;

        return true;
    }

    private function linkToPlannedTransaction(Transaction $transaction, string $value): bool
    {
        $plannedId = (int) $value;

        if (PlannedTransaction::where('id', $plannedId)->where('user_id', $transaction->user_id)->exists()) {
            $transaction->planned_transaction_id = $plannedId;
        }

        return true;
    }
}
