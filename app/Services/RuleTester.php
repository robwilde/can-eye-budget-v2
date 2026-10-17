<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\RuleTestResult;
use App\DTOs\RuleTestRow;
use App\Enums\RuleActionType;
use App\Enums\RuleTestSkipReason;
use App\Models\Transaction;
use App\Models\UserRule;

/**
 * Dry-runs an unsaved rule against a user's current history and reports what
 * "Apply now" would do to each row it matches. Writes nothing.
 */
final readonly class RuleTester
{
    public const int ROW_LIMIT = 50;

    public function __construct(
        private UserRuleApplier $applier,
        private RuleEvaluator $evaluator,
        private RuleActionExecutor $executor,
    ) {}

    /**
     * @param  array<int, array<string, string>>  $triggers
     * @param  array<int, array<string, string>>  $actions
     */
    public function test(int $userId, array $triggers, bool $strictMode, array $actions): RuleTestResult
    {
        $draft = new UserRule([
            'user_id' => $userId,
            'triggers' => $triggers,
            'strict_mode' => $strictMode,
            'actions' => $actions,
        ]);

        $target = $this->executor->targetCategory($actions);
        $lookups = new RuleActionLookups;
        $folds = $this->hasAction($actions, RuleActionType::FoldIntoParent);
        $notYetApplied = [];
        $skipped = [];
        $alreadyApplied = [];
        $notYetAppliedCount = 0;
        $skippedCount = 0;
        $alreadyAppliedCount = 0;

        $this->applier->eligible($userId)
            ->with(['account', 'category.parent.parent', 'splits'])
            ->lazyById()
            ->each(function (Transaction $transaction) use (
                $draft,
                $actions,
                $target,
                $folds,
                $lookups,
                &$notYetApplied,
                &$skipped,
                &$alreadyApplied,
                &$notYetAppliedCount,
                &$skippedCount,
                &$alreadyAppliedCount,
            ): void {
                if (! $this->evaluator->matches($transaction, $draft)) {
                    return;
                }

                $keptByHand = $target !== null
                    && $transaction->categoryProtectedFromRules()
                    && $transaction->category_id !== $target;

                if ($this->executor->wouldChange($transaction, $actions, $lookups)) {
                    $notYetAppliedCount++;
                    $this->keep($notYetApplied, $this->row($transaction, categoryKept: $keptByHand));
                } elseif ($keptByHand) {
                    $skippedCount++;
                    $this->keep($skipped, $this->row($transaction, skipReason: RuleTestSkipReason::CategorisedByHand));
                } elseif ($folds) {
                    $skippedCount++;
                    $this->keep($skipped, $this->row($transaction, skipReason: RuleTestSkipReason::FoldNotPossible));
                } else {
                    $alreadyAppliedCount++;
                    $this->keep($alreadyApplied, $this->row($transaction));
                }
            });

        return new RuleTestResult(
            notYetAppliedCount: $notYetAppliedCount,
            skippedCount: $skippedCount,
            alreadyAppliedCount: $alreadyAppliedCount,
            appendsNotes: $this->hasAction($actions, RuleActionType::AppendNotes),
            notYetApplied: $this->newest($notYetApplied),
            skipped: $this->newest($skipped),
            alreadyApplied: $this->newest($alreadyApplied),
        );
    }

    /**
     * @param  array<int, array<string, string>>  $actions
     */
    private function hasAction(array $actions, RuleActionType $type): bool
    {
        return array_any($actions, fn (array $action): bool => ($action['type'] ?? null) === $type->value);
    }

    /**
     * @param  list<RuleTestRow>  $rows
     */
    private function keep(array &$rows, RuleTestRow $row): void
    {
        $rows[] = $row;

        if (count($rows) >= self::ROW_LIMIT * 2) {
            $rows = $this->newest($rows);
        }
    }

    /**
     * @param  list<RuleTestRow>  $rows
     * @return list<RuleTestRow>
     */
    private function newest(array $rows): array
    {
        usort($rows, static fn (RuleTestRow $a, RuleTestRow $b): int => [$b->postDate, $b->id] <=> [$a->postDate, $a->id]);

        return array_slice($rows, 0, self::ROW_LIMIT);
    }

    private function row(Transaction $transaction, bool $categoryKept = false, ?RuleTestSkipReason $skipReason = null): RuleTestRow
    {
        return new RuleTestRow(
            id: $transaction->id,
            postDate: $transaction->post_date->toDateString(),
            name: $transaction->clean_description ?? $transaction->description,
            amount: abs($transaction->amount),
            direction: $transaction->direction,
            accountName: $transaction->account->name,
            categoryPath: $transaction->category?->fullPath(),
            categoryKept: $categoryKept,
            skipReason: $skipReason,
        );
    }
}
