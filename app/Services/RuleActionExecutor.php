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
     * @param  bool  $overwriteCleanDescription  False leaves a non-blank clean
     *                                           description alone. The import
     *                                           pipeline re-runs rules on every
     *                                           new version of a row, so an
     *                                           overwrite there would revert a
     *                                           person's later rename.
     * @return bool Whether the rule fully applied. Returns false when a fold
     *              action was requested but did not fold (e.g. an orphan fee
     *              whose parent is not yet imported), so the caller leaves it
     *              unaudited for the next pipeline run to retry — even if other
     *              actions in the same rule took effect.
     */
    public function execute(Transaction $transaction, array $actions, bool $overwriteCleanDescription = true): bool
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
                RuleActionType::SetCleanDescription => $this->setCleanDescription($transaction, $value, $overwriteCleanDescription),
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
     * The category these actions would assign to this transaction, or null if
     * they would leave it alone.
     *
     * Read-only: it mutates nothing and saves nothing. This exists so a preview
     * can ask "what would happen" without going anywhere near execute(), which
     * ends in $transaction->save(). Sharing the decision with setCategory()
     * below means a preview cannot disagree with the write.
     *
     * execute() runs every SetCategory action in order and a hidden category is
     * skipped, so the last visible one is what lands; this resolves the same.
     *
     * @param  array<int, array<string, string>>  $actions
     */
    public function categoryItWouldSet(Transaction $transaction, array $actions): ?int
    {
        if (! $this->maySetCategory($transaction)) {
            return null;
        }

        $categoryIds = [];

        foreach ($actions as $action) {
            if (($action['type'] ?? null) === RuleActionType::SetCategory->value) {
                $categoryIds[] = (int) ($action['value'] ?? 0);
            }
        }

        if ($categoryIds === []) {
            return null;
        }

        // Cast: some drivers return ids as strings, and the match is strict.
        $visible = array_map(intval(...), Category::visible()->whereIn('id', $categoryIds)->pluck('id')->all());

        foreach (array_reverse($categoryIds) as $categoryId) {
            if (in_array($categoryId, $visible, true)) {
                return $categoryId;
            }
        }

        return null;
    }

    /**
     * A rule fills gaps; it never overrides a person, a split's parts, or a
     * transfer. A categorised row that never declared a source counts as a
     * person's: saving() would default it to Manual.
     */
    private function maySetCategory(Transaction $transaction): bool
    {
        if ($transaction->categoryProtectedFromRules()) {
            return false;
        }

        if ($transaction->isSplit()) {
            return false;
        }

        return $transaction->transfer_pair_id === null;
    }

    /**
     * The return value reports "this action was handled", not "the row
     * changed". A protected row reports handled (true) so the pipeline audits
     * it and stops retrying it.
     *
     * A split reports not-applied (false): the skip is transient, because
     * un-splitting restores the same row id, and an audit entry would suppress
     * this rule on that row forever. A transfer reports handled (true):
     * converting away from a transfer mints a new row id, so the audit cannot
     * strand it.
     */
    private function setCategory(Transaction $transaction, string $value): bool
    {
        if (! $this->maySetCategory($transaction)) {
            // Precedence mirrors maySetCategory(): protection is decided before
            // the split test, so a protected split still reports handled.
            return $transaction->categoryProtectedFromRules() || ! $transaction->isSplit();
        }

        $categoryId = (int) $value;

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

    /**
     * Reports handled even when it keeps an existing name, so the pipeline
     * audits the row and stops retrying it.
     *
     * On a split the pipeline still fills the name but reports not-applied,
     * as setCategory() does, so the split alone never audits the row and the
     * rule still categorises it once it is un-split. Filling a blank name
     * again on the next sync changes nothing.
     */
    private function setCleanDescription(Transaction $transaction, string $value, bool $overwrite): bool
    {
        if ($overwrite || mb_trim($transaction->clean_description ?? '') === '') {
            $transaction->clean_description = $value;
        }

        return $overwrite || ! $transaction->isSplit();
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
