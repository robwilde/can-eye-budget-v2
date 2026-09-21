<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRuleGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a user's already-existing auto-apply rules to their uncategorised
 * backlog.
 *
 * This exists because rules and history drift apart: a rule created today does
 * not retroactively reach rows imported last month unless something sweeps
 * them. Measured on a real database, 349 of 807 uncategorised transactions were
 * matched by rules that already existed — so the backlog is substantially
 * clearable without inventing a single new rule.
 *
 * Deliberately narrow:
 * - only rows with no category are considered, so nothing is ever overwritten;
 * - splits and transfers are excluded, as everywhere else;
 * - writes go through RuleActionExecutor, so rows are stamped
 *   CategorySource::Rule and stay reviewable and revertible.
 *
 * Idempotent by construction: a row that gets a category drops out of the
 * uncategorised scope, so re-running finds nothing left to do.
 */
final readonly class RuleBacklogApplier
{
    public function __construct(
        private RuleEvaluator $evaluator,
        private RuleActionExecutor $executor,
    ) {}

    /**
     * @return array{scanned: int, categorised: int}
     */
    public function apply(User $user, bool $dryRun = false): array
    {
        $groups = UserRuleGroup::query()
            ->where('user_id', $user->id)
            ->active()
            ->ordered()
            ->with(['rules' => fn ($q) => $q->active()->ordered()])
            ->get();

        $scanned = 0;
        $categorised = 0;

        if ($groups->isEmpty()) {
            return ['scanned' => 0, 'categorised' => 0];
        }

        $this->backlog($user)->lazyById()->each(function (Transaction $transaction) use (
            $groups,
            $dryRun,
            &$scanned,
            &$categorised,
        ): void {
            $scanned++;

            foreach ($groups as $group) {
                foreach ($group->rules as $rule) {
                    if (! $rule->is_auto_apply || ! $this->evaluator->matches($transaction, $rule)) {
                        continue;
                    }

                    if ($dryRun) {
                        // Read-only resolution. An earlier version of this
                        // probed with $transaction->replicate() and ran the
                        // executor against the copy — but execute() ends in
                        // save(), and saving a replica INSERTS it, so the
                        // "dry" run silently created a duplicate transaction
                        // for every match. Never call execute() to ask a
                        // question.
                        if ($this->executor->categoryItWouldSet($transaction, $rule->actions) !== null) {
                            $categorised++;

                            return;
                        }

                        continue;
                    }

                    $this->executor->execute($transaction, $rule->actions);

                    if ($transaction->category_id !== null) {
                        $categorised++;

                        return;
                    }
                }

                if ($group->stop_processing) {
                    return;
                }
            }
        });

        return ['scanned' => $scanned, 'categorised' => $categorised];
    }

    public function backlogCount(User $user): int
    {
        return $this->backlog($user)->count();
    }

    /**
     * Uncategorised rows a rule may legally touch. Restricting to uncategorised
     * is what makes this safe to run at any time: there is nothing to overwrite.
     *
     * @return Builder<Transaction>
     */
    private function backlog(User $user): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->whereNull('category_id')
            ->whereNull('transfer_pair_id')
            ->whereDoesntHave('splits');
    }
}
