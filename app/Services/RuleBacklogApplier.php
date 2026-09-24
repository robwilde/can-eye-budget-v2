<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
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
 * - only rows with no category are considered, and the planned-group fan-out
 *   is switched off, so nothing is ever overwritten — not even a sibling;
 * - splits and transfers are excluded, as everywhere else;
 * - only a rule's SetCategory actions run. The sweep has no audit ledger, so
 *   running anything else (AppendNotes, say) would repeat it on every sweep
 *   for a row the rule matched but left uncategorised;
 * - writes go through RuleActionExecutor, so rows are stamped
 *   CategorySource::Rule and stay reviewable and revertible.
 *
 * Idempotent by construction: a row that gets a category drops out of the
 * uncategorised scope, and a row that does not had nothing written to it, so
 * re-running changes nothing that an earlier run already decided.
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
                // Mirrors UserRulesStage: stop_processing ends the walk only
                // when some rule in the group matched, auto-apply or not.
                $groupMatched = false;

                foreach ($group->rules as $rule) {
                    if (! $this->evaluator->matches($transaction, $rule)) {
                        continue;
                    }

                    $groupMatched = true;

                    if (! $rule->is_auto_apply) {
                        continue;
                    }

                    $actions = $this->categoryActions($rule->actions);

                    if ($dryRun) {
                        // Read-only resolution. An earlier version of this
                        // probed with $transaction->replicate() and ran the
                        // executor against the copy — but execute() ends in
                        // save(), and saving a replica INSERTS it, so the
                        // "dry" run silently created a duplicate transaction
                        // for every match. Never call execute() to ask a
                        // question.
                        if ($this->executor->categoryItWouldSet($transaction, $actions) !== null) {
                            $categorised++;

                            return;
                        }

                        continue;
                    }

                    // The planned-group fan-out would rewrite siblings the rule
                    // does not match, including ones a person categorised.
                    $transaction->propagateCategoryChange = false;
                    $this->executor->execute($transaction, $actions);

                    if ($transaction->category_id !== null) {
                        $categorised++;

                        return;
                    }
                }

                if ($groupMatched && $group->stop_processing) {
                    return;
                }
            }
        });

        return ['scanned' => $scanned, 'categorised' => $categorised];
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     * @return array<int, array<string, string>>
     */
    private function categoryActions(array $actions): array
    {
        return array_values(array_filter(
            $actions,
            static fn (array $action): bool => ($action['type'] ?? null) === RuleActionType::SetCategory->value,
        ));
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
