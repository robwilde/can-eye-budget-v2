<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\UserRule;
use Illuminate\Support\Collection;

/**
 * Finds the rows a human categorised themselves that a draft rule would match,
 * split into those already filed under the rule's category and those filed
 * elsewhere. A rule matching the latter contradicts a manual decision: the
 * executor leaves those rows alone, but the rule still encodes a claim the
 * user has already refuted.
 *
 * Shared by CategoryRuleGenerator::preview() and CategoryRuleMiner so the
 * warning the user sees and the miner's reject/narrow decision cannot drift.
 */
final readonly class ManualContradictionChecker
{
    public const string UNCATEGORISED = 'Uncategorised';

    public function __construct(
        private RuleEvaluator $evaluator,
        private UserRuleApplier $applier,
    ) {}

    /**
     * Classify one row already known to match the rule: null when it is not
     * protected by a manual category, true when it agrees with the target,
     * false when it contradicts it.
     */
    public function classify(Transaction $transaction, int $targetCategoryId): ?bool
    {
        if (! $transaction->categoryProtectedFromRules()) {
            return null;
        }

        return $transaction->category_id === $targetCategoryId;
    }

    /** Full category path used to key contradiction reports. */
    public function pathOf(Transaction $transaction): string
    {
        return $transaction->category?->fullPath() ?? self::UNCATEGORISED;
    }

    /**
     * @param  list<array<string, string>>  $triggers  strict-mode (AND) triggers: [{field, operator, value}, ...]
     * @return array{agrees: Collection<int, Transaction>, contradicts: Collection<int, Transaction>, contradictingCategories: array<string, int>}
     */
    public function check(int $userId, int $targetCategoryId, array $triggers): array
    {
        $draft = new UserRule([
            'user_id' => $userId,
            'strict_mode' => true,
            'triggers' => $triggers,
        ]);

        /** @var Collection<int, Transaction> $agrees */
        $agrees = new Collection;
        /** @var Collection<int, Transaction> $contradicts */
        $contradicts = new Collection;
        $contradictingCategories = [];

        $this->applier->eligible($userId)
            ->with('category.parent.parent')
            ->lazyById()
            ->each(function (Transaction $transaction) use ($draft, $targetCategoryId, $agrees, $contradicts, &$contradictingCategories): void {
                if (! $this->evaluator->matches($transaction, $draft)) {
                    return;
                }

                $verdict = $this->classify($transaction, $targetCategoryId);

                if ($verdict === true) {
                    $agrees->push($transaction);
                } elseif ($verdict === false) {
                    $contradicts->push($transaction);
                    $path = $this->pathOf($transaction);
                    $contradictingCategories[$path] = ($contradictingCategories[$path] ?? 0) + 1;
                }
            });

        arsort($contradictingCategories);

        return [
            'agrees' => $agrees,
            'contradicts' => $contradicts,
            'contradictingCategories' => $contradictingCategories,
        ];
    }
}
