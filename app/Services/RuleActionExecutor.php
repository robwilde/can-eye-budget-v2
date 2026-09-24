<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;

final readonly class RuleActionExecutor
{
    public function __construct(private TransactionFeeFolder $feeFolder) {}

    /**
     * @param  array<int, array<string, string>>  $actions
     * @return bool Whether the rule fully applied. Returns false when a fold
     *              action was requested but did not fold (e.g. an orphan fee
     *              whose parent is not yet imported), so the caller leaves it
     *              unaudited for the next pipeline run to retry — even if other
     *              actions in the same rule took effect.
     */
    public function execute(Transaction $transaction, array $actions): bool
    {
        $applied = false;
        $foldPending = false;

        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $value = $action['value'] ?? '';

            $effect = match ($type) {
                RuleActionType::SetCategory => $this->setCategory($transaction, $value),
                RuleActionType::SetDescription => $this->setDescription($transaction, $value),
                RuleActionType::AppendNotes => $this->appendNotes($transaction, $value),
                RuleActionType::SetNotes => $this->setNotes($transaction, $value),
                RuleActionType::LinkToPlannedTransaction => $this->linkToPlannedTransaction($transaction, $value),
                RuleActionType::FoldIntoParent => $this->feeFolder->fold($transaction) instanceof Transaction,
            };

            if ($type === RuleActionType::FoldIntoParent && ! $effect) {
                $foldPending = true;
            }

            $applied = $effect || $applied;
        }

        if ($transaction->isDirty()) {
            $transaction->save();
        }

        return $applied && ! $foldPending;
    }

    /**
     * A rule fills gaps; it never overrides a person.
     *
     * A Manual source — or a categorised row that never declared one, which
     * saving() would default to Manual — is protected, and reports handled
     * (true) so the pipeline audits the row and stops retrying it.
     *
     * A split reports not-applied (false): the skip is transient, because
     * un-splitting restores the same row id, and an audit entry would suppress
     * this rule on that row forever. A transfer reports handled (true):
     * converting away from a transfer mints a new row id, so the audit cannot
     * strand it.
     */
    private function setCategory(Transaction $transaction, string $value): bool
    {
        $categoryId = (int) $value;

        if ($transaction->categoryProtectedFromRules()) {
            return true;
        }

        if ($transaction->isSplit()) {
            return false;
        }

        if ($transaction->transfer_pair_id !== null) {
            return true;
        }

        if (Category::visible()->where('id', $categoryId)->exists()) {
            $transaction->category_id = $categoryId;
            $transaction->category_source = CategorySource::Rule;
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
