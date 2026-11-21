<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategorySource;
use App\Enums\CleanDescriptionSource;
use App\Enums\ExclusionSource;
use App\Enums\RuleActionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\Transfers\TransferDetector;
use App\Support\CleanDescriptionDeriver;
use Illuminate\Support\Facades\DB;

final readonly class RuleActionExecutor
{
    public function __construct(private TransactionFeeFolder $feeFolder, private TransferDetector $transfers) {}

    /**
     * @param  array<int, array<string, string>>  $actions
     * @param  bool  $overwriteCleanDescription  False never replaces a name a
     *                                           person or a rule set, nor a
     *                                           legacy name with no source, but
     *                                           does replace an import-derived
     *                                           name (feed, brand or derived).
     *                                           The import pipeline re-runs
     *                                           rules on every new version of a
     *                                           row, so an overwrite there
     *                                           would revert a person's later
     *                                           rename.
     * @param  bool  $overrideLocks  True lets a user-initiated re-apply replace a
     *                               hand-set category or exclusion. Pipeline runs
     *                               never pass it. Split and transfer rows stay
     *                               protected either way.
     * @return bool Whether the rule fully applied. Returns false when a fold
     *              action was requested but did not fold (e.g. an orphan fee
     *              whose parent is not yet imported), so the caller leaves it
     *              unaudited for the next pipeline run to retry — even if other
     *              actions in the same rule took effect.
     */
    public function execute(Transaction $transaction, array $actions, bool $overwriteCleanDescription = true, bool $overrideLocks = false): bool
    {
        if ($overwriteCleanDescription || ! $transaction->exists || ! $this->setsCleanDescription($actions)) {
            return $this->run($transaction, $actions, $overwriteCleanDescription, $overrideLocks);
        }

        return DB::transaction(function () use ($transaction, $actions, $overrideLocks): bool {
            $this->syncPersistedCleanDescription($transaction);

            return $this->run($transaction, $actions, false, $overrideLocks);
        });
    }

    /**
     * Whether a lock stops these actions from changing this row when a pipeline
     * run applies them: the same actions staged with and without overrideLocks
     * leave the category, exclusion or clean description different.
     *
     * Read-only: the actions are staged on clones that are never saved.
     *
     * @param  array<int, array<string, string>>  $actions
     */
    public function blockedByLocks(Transaction $transaction, array $actions): bool
    {
        $pipeline = clone $transaction;
        $override = clone $transaction;

        $this->stage($pipeline, $actions, false, false);
        $this->stage($override, $actions, true, true);

        return $pipeline->category_id !== $override->category_id
            || $pipeline->excluded !== $override->excluded
            || $pipeline->clean_description !== $override->clean_description;
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
    public function categoryItWouldSet(Transaction $transaction, array $actions, bool $overrideLocks = false): ?int
    {
        return $this->maySetCategory($transaction, $overrideLocks) ? $this->targetCategory($actions, $transaction->user_id) : null;
    }

    /**
     * The category these actions assign when nothing blocks them: the last
     * visible SetCategory, or null if there is none.
     *
     * @param  array<int, array<string, string>>  $actions
     */
    public function targetCategory(array $actions, int $userId): ?int
    {
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
        $visible = array_map(intval(...), Category::visible()->where('user_id', $userId)->whereIn('id', $categoryIds)->pluck('id')->all());

        foreach (array_reverse($categoryIds) as $categoryId) {
            if (in_array($categoryId, $visible, true)) {
                return $categoryId;
            }
        }

        return null;
    }

    /**
     * Whether execute() would change this row, as "Apply now" runs it
     * (overwriting clean descriptions).
     *
     * Read-only: the actions are staged on a clone that is never saved, so no
     * model event fires and the given row is left untouched. It runs the same
     * setters as run(), in order, so the verdict cannot drift from the write.
     * A fold counts as a change whenever a parent exists, and an append_notes
     * action always counts because it adds the note on every run.
     *
     * @param  array<int, array<string, string>>  $actions
     * @param  RuleActionLookups|null  $lookups  answers repeated existence checks
     *                                           once per key across many rows; null
     *                                           queries live, as execute() does
     */
    public function wouldChange(Transaction $transaction, array $actions, ?RuleActionLookups $lookups = null, bool $overrideLocks = false): bool
    {
        $draft = clone $transaction;

        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $value = $action['value'] ?? '';

            $effect = match ($type) {
                RuleActionType::SetCategory => $this->setCategory($draft, $value, $overrideLocks, $lookups),
                RuleActionType::SetDescription => $this->setDescription($draft, $value),
                RuleActionType::SetCleanDescription => $this->setCleanDescription($draft, $value, true),
                RuleActionType::AppendNotes => $this->appendNotes($draft, $value),
                RuleActionType::SetNotes => $this->setNotes($draft, $value),
                RuleActionType::LinkToPlannedTransaction => $this->linkToPlannedTransaction($draft, $value, $lookups),
                RuleActionType::FoldIntoParent => $this->feeFolder->foldableParent($draft) instanceof Transaction,
                RuleActionType::ExcludeFromReports => $this->excludeFromReports($draft, $overrideLocks),
                RuleActionType::MarkAsTransfer => $this->canMarkAsTransfer($draft, $value),
            };

            if (in_array($type, [RuleActionType::FoldIntoParent, RuleActionType::MarkAsTransfer], true) && $effect) {
                return true;
            }
        }

        $draft->clearBlankCleanDescription();

        return $draft->isDirty();
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     */
    private function stage(Transaction $draft, array $actions, bool $overwriteCleanDescription, bool $overrideLocks): void
    {
        foreach ($actions as $action) {
            $value = $action['value'] ?? '';

            match (RuleActionType::tryFrom($action['type'] ?? '')) {
                RuleActionType::SetCategory => $this->setCategory($draft, $value, $overrideLocks),
                RuleActionType::SetCleanDescription => $this->setCleanDescription($draft, $value, $overwriteCleanDescription),
                RuleActionType::ExcludeFromReports => $this->excludeFromReports($draft, $overrideLocks),
                default => null,
            };
        }
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     */
    private function setsCleanDescription(array $actions): bool
    {
        foreach ($actions as $action) {
            if (RuleActionType::tryFrom($action['type'] ?? '') === RuleActionType::SetCleanDescription) {
                return true;
            }
        }

        return false;
    }

    private function syncPersistedCleanDescription(Transaction $transaction): void
    {
        $fresh = Transaction::query()
            ->whereKey($transaction->getKey())
            ->lockForUpdate()
            ->first(['id', 'clean_description', 'clean_description_source']);

        if ($fresh === null || $transaction->isDirty(['clean_description', 'clean_description_source'])) {
            return;
        }

        $transaction
            ->forceFill([
                'clean_description' => $fresh->clean_description,
                'clean_description_source' => $fresh->clean_description_source,
            ])
            ->syncOriginalAttributes(['clean_description', 'clean_description_source']);
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     */
    private function run(Transaction $transaction, array $actions, bool $overwriteCleanDescription, bool $overrideLocks): bool
    {
        $applied = false;
        $pending = false;
        $transferActions = [];

        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom($action['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $value = $action['value'] ?? '';

            $effect = match ($type) {
                RuleActionType::SetCategory => $this->setCategory($transaction, $value, $overrideLocks),
                RuleActionType::SetDescription => $this->setDescription($transaction, $value),
                RuleActionType::SetCleanDescription => $this->setCleanDescription($transaction, $value, $overwriteCleanDescription),
                RuleActionType::AppendNotes => $this->appendNotes($transaction, $value),
                RuleActionType::SetNotes => $this->setNotes($transaction, $value),
                RuleActionType::LinkToPlannedTransaction => $this->linkToPlannedTransaction($transaction, $value),
                RuleActionType::FoldIntoParent => $this->feeFolder->fold($transaction) instanceof Transaction,
                RuleActionType::ExcludeFromReports => $this->excludeFromReports($transaction, $overrideLocks),
                RuleActionType::MarkAsTransfer => $this->deferTransfer($transferActions, $value),
            };

            if ($type === RuleActionType::FoldIntoParent && ! $effect) {
                $pending = true;
            }

            $applied = $effect || $applied;
        }

        if ($transaction->isDirty()) {
            $transaction->save();
        }

        foreach ($transferActions as $accountId) {
            if ($this->markAsTransfer($transaction, $accountId)) {
                $applied = true;
            } else {
                $pending = true;
            }
        }

        return $applied && ! $pending;
    }

    /**
     * The link writes through TransferLinker under its own locks, so it runs after the row's
     * other changes are saved and its refresh cannot discard them.
     *
     * @param  list<string>  $queue
     */
    private function deferTransfer(array &$queue, string $value): bool
    {
        $queue[] = $value;

        return false;
    }

    /**
     * A row already in a transfer, or aimed at its own, an inactive, a deleted or a foreign account,
     * is left alone and reported handled. A tracked target with no single real opposite row leaves the row
     * unlinked and not handled, so a later run can pair it once that row arrives.
     */
    private function markAsTransfer(Transaction $transaction, string $value): bool
    {
        $target = $this->transferTarget($transaction, $value);

        if (! $target instanceof Account || $transaction->transfer_pair_id !== null || $target->id === $transaction->account_id) {
            return true;
        }

        return $this->transfers->linkToCounterpart($transaction, $target);
    }

    private function canMarkAsTransfer(Transaction $transaction, string $value): bool
    {
        $target = $this->transferTarget($transaction, $value);

        return $target instanceof Account && $this->transfers->canLinkToCounterpart($transaction, $target);
    }

    private function transferTarget(Transaction $transaction, string $value): ?Account
    {
        return Account::query()->where('user_id', $transaction->user_id)->active()->find((int) $value);
    }

    /**
     * A rule fills gaps; it never overrides a person, a split's parts, or a
     * transfer. A categorised row that never declared a source counts as a
     * person's: saving() would default it to Manual.
     */
    private function maySetCategory(Transaction $transaction, bool $overrideLocks = false): bool
    {
        if (! $overrideLocks && $transaction->categoryProtectedFromRules()) {
            return false;
        }

        return ! $this->isStructurallyProtected($transaction);
    }

    /**
     * Split rows and transfer legs are never rewritten by a rule, whoever asked.
     */
    private function isStructurallyProtected(Transaction $transaction): bool
    {
        return $transaction->transfer_pair_id !== null
            || $transaction->isSplit()
            || $transaction->isSplitParent()
            || $transaction->isSplitChild();
    }

    private function splitIsInFlux(Transaction $transaction): bool
    {
        return $transaction->isSplit() || $transaction->isSplitParent();
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
    private function setCategory(Transaction $transaction, string $value, bool $overrideLocks, ?RuleActionLookups $lookups = null): bool
    {
        if (! $this->maySetCategory($transaction, $overrideLocks)) {
            // Precedence mirrors maySetCategory(): protection is decided before
            // the split test, so a protected split still reports handled.
            return (! $overrideLocks && $transaction->categoryProtectedFromRules()) || ! $this->splitIsInFlux($transaction);
        }

        $categoryId = (int) $value;

        if ($lookups?->categoryIsVisible($categoryId, $transaction->user_id) ?? Category::visible()->where('user_id', $transaction->user_id)->where('id', $categoryId)->exists()) {
            $transaction->category_id = $categoryId;
            $transaction->category_source = CategorySource::Rule;
        }

        return true;
    }

    /**
     * Reports handled when a hand-set exclusion or a protected row keeps the
     * row as it is, so the pipeline audits it and stops retrying it. A split
     * in flux reports not-applied, as setCategory() does.
     */
    private function excludeFromReports(Transaction $transaction, bool $overrideLocks): bool
    {
        if ($this->isStructurallyProtected($transaction)) {
            return ! $this->splitIsInFlux($transaction);
        }

        if ($transaction->excluded) {
            return true;
        }

        if (! $overrideLocks && $transaction->excluded_source === ExclusionSource::Manual) {
            return true;
        }

        $transaction->excluded = true;
        $transaction->excluded_source = ExclusionSource::Rule;

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
        if ($overwrite) {
            $transaction->clean_description = CleanDescriptionDeriver::tidy($value);
            $transaction->clean_description_source = CleanDescriptionSource::Rule;
        } else {
            $transaction->offerCleanDescription($value, CleanDescriptionSource::Rule);
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

    private function linkToPlannedTransaction(Transaction $transaction, string $value, ?RuleActionLookups $lookups = null): bool
    {
        $plannedId = (int) $value;

        if ($lookups?->planIsOwnedBy($plannedId, $transaction->user_id) ?? PlannedTransaction::where('id', $plannedId)->where('user_id', $transaction->user_id)->exists()) {
            $transaction->planned_transaction_id = $plannedId;
        }

        return true;
    }
}
