<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RuleApplyResult;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Support\Rules\RuleRunScope;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Applies one rule to a user's whole current history, outside the analysis
 * pipeline. Used when a generated rule is created and when a person clicks
 * "Apply now" on the Rules page, whether the rule is auto-apply or not.
 */
final readonly class UserRuleApplier
{
    public function __construct(
        private RuleEvaluator $evaluator,
        private RuleActionExecutor $executor,
    ) {}

    /**
     * Transactions a rule may legally touch.
     *
     * Shared by the sweep and CategoryRuleGenerator::preview(), so the preview
     * counts exactly the population the sweep will walk.
     *
     * Splits and transfers are excluded at the query level as well as in the
     * executor: a split transaction's category is decided by its parts, and a
     * transfer is not spending at all. Note whereDoesntHave('splits') is the
     * split test — parent_transaction_id is createChild() lineage and means
     * something entirely different.
     *
     * @return Builder<Transaction>
     */
    public function eligible(int $userId): Builder
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->whereNull('transfer_pair_id')
            ->whereDoesntHave('splits');
    }

    /**
     * @param  bool  $overrideLocks  Only a user-initiated re-apply passes true: it replaces a
     *                               hand-set category or exclusion. Split and transfer rows
     *                               stay protected.
     * @param  RuleFieldClaims|null  $claims  Shared across rules applied in order: a field a
     *                                        higher rule claimed on a row is left alone, and this
     *                                        rule claims its own for the rules below.
     * @return int Rows the executor reported handled. That includes a matched
     *             row whose category a person set: the executor leaves it
     *             untouched but reports it handled, as the pipeline does.
     */
    public function applyToHistory(UserRule $rule, bool $overrideLocks = false, ?RuleFieldClaims $claims = null): int
    {
        return $this->apply($rule, $overrideLocks, $claims)->handled;
    }

    /**
     * As applyToHistory(), but reports how many rows the rule matched and how many it changed.
     *
     * @param  bool  $overrideLocks  see applyToHistory()
     * @param  RuleFieldClaims|null  $claims  see applyToHistory()
     * @param  RuleRunScope|null  $scope  Rows a higher stop-processing group matched are skipped, and
     *                                    this rule's matches are noted for the groups below.
     */
    public function apply(UserRule $rule, bool $overrideLocks = false, ?RuleFieldClaims $claims = null, ?RuleRunScope $scope = null): RuleApplyResult
    {
        $applied = 0;
        $processed = 0;
        $modified = 0;

        // Stream by id rather than loading the whole history into memory. Keying
        // on the (unchanging) id keeps paging stable even though we mutate rows.
        $this->eligible($rule->user_id)
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rule, $overrideLocks, $claims, $scope, &$applied, &$processed, &$modified): void {
                if ($scope?->isStopped($transaction->id) || ! $this->evaluator->matches($transaction, $rule)) {
                    return;
                }

                $scope?->noteMatch($transaction->id);

                $processed++;

                // Write exactly the matched row. The planned-group fan-out
                // would rewrite siblings the rule does not match, including
                // ones a person categorised, none of which preview() counts.
                // applyCategoryToSelection() opts out for the same reason.
                $transaction->propagateCategoryChange = false;

                $actions = $claims?->unclaimed($transaction->id, $rule->actions) ?? $rule->actions;

                if ($actions !== [] && $this->executor->wouldChange($transaction, $actions, null, $overrideLocks)) {
                    $modified++;
                }

                try {
                    if ($actions !== [] && $this->executor->execute($transaction, $actions, overrideLocks: $overrideLocks)) {
                        $applied++;
                    }
                } catch (Throwable $exception) {
                    rescue(fn () => $claims?->claim($transaction->id, $rule->actions, fn (array $action): bool => $this->executor->canAct($transaction, $action)), report: false);

                    throw $exception;
                }

                $claims?->claim($transaction->id, $rule->actions, fn (array $action): bool => $this->executor->canAct($transaction, $action));
            });

        return new RuleApplyResult(processed: $processed, modified: $modified, handled: $applied);
    }

    /**
     * Notes the rows a rule matches without acting on them, for a suggestion-only rule: it neither
     * applies nor claims in a sync, but a match still counts when its group stops processing.
     */
    public function observe(UserRule $rule, RuleRunScope $scope): void
    {
        $this->eligible($rule->user_id)
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rule, $scope): void {
                if (! $scope->isStopped($transaction->id) && $this->evaluator->matches($transaction, $rule)) {
                    $scope->noteMatch($transaction->id);
                }
            });
    }
}
