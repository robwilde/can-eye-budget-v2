<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\UserRule;
use Illuminate\Database\Eloquent\Builder;

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
     * @return int Rows the executor reported handled. That includes a matched
     *             row whose category a person set: the executor leaves it
     *             untouched but reports it handled, as the pipeline does.
     */
    public function applyToHistory(UserRule $rule): int
    {
        $applied = 0;

        // Stream by id rather than loading the whole history into memory. Keying
        // on the (unchanging) id keeps paging stable even though we mutate rows.
        $this->eligible($rule->user_id)
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rule, &$applied): void {
                if (! $this->evaluator->matches($transaction, $rule)) {
                    return;
                }

                // Write exactly the matched row. The planned-group fan-out
                // would rewrite siblings the rule does not match, including
                // ones a person categorised, none of which preview() counts.
                // applyCategoryToSelection() opts out for the same reason.
                $transaction->propagateCategoryChange = false;

                if ($this->executor->execute($transaction, $rule->actions)) {
                    $applied++;
                }
            });

        return $applied;
    }
}
