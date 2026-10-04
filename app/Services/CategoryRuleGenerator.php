<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CategoryRulePreview;
use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Support\Recurring\MerchantSignature;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds a "categorise this merchant" rule from a source transaction and a
 * chosen category, then applies it to the user's existing transactions. The
 * rule persists and auto-applies, so previous transactions are categorised
 * immediately and future imports keep being categorised by the normal pipeline.
 */
final readonly class CategoryRuleGenerator
{
    private const string GROUP_NAME = 'Auto-categorisation';

    /**
     * Payment-network / method tokens that lead many card descriptions
     * ("VISA -NETFLIX.COM", "EFTPOS WOOLWORTHS"). They are not the payee, so
     * merchantToken() skips them whenever a more distinctive token is available
     * — otherwise the rule would match every card transaction. A generic token
     * is used only as a last resort, when the signature contains nothing else.
     *
     * @var list<string>
     */
    private const array GENERIC_TOKENS = [
        'VISA', 'MASTERCARD', 'MC', 'AMEX', 'EFTPOS', 'PAYPAL', 'SQ', 'SQUARE',
        'POS', 'PURCHASE', 'PAYMENT', 'DEBIT', 'CREDIT', 'PAYWAVE', 'PAYPASS',
        'CONTACTLESS', 'TAP', 'WITHDRAWAL', 'DEPOSIT', 'TRANSFER',
    ];

    public function __construct(
        private RuleEvaluator $evaluator,
        private UserRuleApplier $applier,
        private ManualContradictionChecker $contradictions,
    ) {}

    /**
     * A non-blank $cleanDescription adds a SetCleanDescription action: the
     * sweep renames every match and the import pipeline names new ones. The
     * raw description, which the triggers read, is never written.
     */
    public function generateAndApply(
        Transaction $source,
        int $categoryId,
        ?string $matchValue = null,
        ?string $cleanDescription = null,
    ): UserRule {
        $group = $this->group($source->user_id);

        $rule = UserRule::query()->create([
            'user_id' => $source->user_id,
            'user_rule_group_id' => $group->id,
            'name' => $this->ruleName($source),
            'strict_mode' => true,
            'is_auto_apply' => true,
            'is_active' => true,
            'order' => $this->nextRuleOrder($group->id),
            ...$this->matchAttributes($source, $categoryId, $matchValue, $cleanDescription),
        ]);

        $this->applier->applyToHistory($rule);

        return $rule;
    }

    /**
     * What generateAndApply() would do, without creating anything.
     *
     * Dry-runs the *real* RuleEvaluator against an unsaved rule built from the
     * *same* matchAttributes() the commit path uses. A reimplementation here
     * would drift from actual behaviour, and a preview that disagrees with the
     * result is worse than no preview at all.
     *
     * @param  list<int>  $selectedIds  ids the user already had selected, so the
     *                                  preview can separate "what I asked for"
     *                                  from "what else this would touch"
     */
    public function preview(
        Transaction $source,
        int $categoryId,
        ?string $matchValue,
        array $selectedIds = [],
    ): CategoryRulePreview {
        $draft = new UserRule([
            'user_id' => $source->user_id,
            'strict_mode' => true,
            ...$this->matchAttributes($source, $categoryId, $matchValue),
        ]);

        $selected = array_flip($selectedIds);
        $inSelection = 0;
        $beyondSelection = 0;
        $wouldChange = 0;
        $agreesWithManual = 0;
        $contradictsManual = 0;
        $existingCategories = [];
        $contradictingCategories = [];

        $this->applier->eligible($source->user_id)
            ->with('category.parent.parent')
            ->lazyById()
            ->each(function (Transaction $transaction) use (
                $draft,
                $selected,
                $categoryId,
                &$inSelection,
                &$beyondSelection,
                &$wouldChange,
                &$agreesWithManual,
                &$contradictsManual,
                &$existingCategories,
                &$contradictingCategories,
            ): void {
                if (! $this->evaluator->matches($transaction, $draft)) {
                    return;
                }

                $isSelected = isset($selected[$transaction->id]);
                $isSelected ? $inSelection++ : $beyondSelection++;

                // The executor's own guard, so a legacy row with no recorded
                // source is counted as protected exactly as the sweep treats it.
                $verdict = $this->contradictions->classify($transaction, $categoryId);
                $isProtected = $verdict !== null;

                if ($verdict === true) {
                    $agreesWithManual++;
                } elseif ($verdict === false) {
                    $contradictsManual++;
                    $path = $this->contradictions->pathOf($transaction);
                    $contradictingCategories[$path] = ($contradictingCategories[$path] ?? 0) + 1;
                } elseif (
                    $transaction->category_id !== $categoryId
                    // Same category but Feed-sourced: the executor still
                    // restamps it as Rule, so the sweep writes this row too.
                    || $transaction->category_source !== CategorySource::Rule
                ) {
                    $wouldChange++;
                }

                if (
                    ! $isSelected
                    && ! $isProtected
                    && $transaction->category_id !== null
                    && $transaction->category_id !== $categoryId
                ) {
                    // Only rows the sweep will actually move out of a category:
                    // protected rows are already reported, and rows already in
                    // the target leave nothing. Keyed by full path: category
                    // names repeat across branches ('Subscription' under Office
                    // and Personal), and the warning is about which branch rows
                    // would leave.
                    // category_id is a FK with nullOnDelete, so a non-null id
                    // always resolves to a row.
                    $path = $transaction->category->fullPath();
                    $existingCategories[$path] = ($existingCategories[$path] ?? 0) + 1;
                }
            });

        arsort($existingCategories);
        arsort($contradictingCategories);

        return new CategoryRulePreview(
            matchValue: $this->resolvedMatchValue($source, $matchValue),
            inSelection: $inSelection,
            beyondSelection: $beyondSelection,
            wouldChange: $wouldChange,
            agreesWithManual: $agreesWithManual,
            contradictsManual: $contradictsManual,
            existingCategories: $existingCategories,
            contradictingCategories: $contradictingCategories,
        );
    }

    /**
     * Rows a human filed under another category that the rule would match.
     *
     * @return Collection<int, Transaction>
     */
    public function manualContradictions(Transaction $source, int $categoryId, ?string $matchValue): Collection
    {
        return $this->contradictions
            ->check($source->user_id, $categoryId, [$this->buildTrigger($source, $matchValue)])['contradicts'];
    }

    /**
     * Re-file the contradicting rows the user agreed to move.
     *
     * The contradictions are recomputed rather than taken from the form, and
     * a row moves only when it still contradicts the rule and is among
     * $consentedIds — the rows the user was shown. A row that started
     * contradicting after the list was rendered stays where the user put it.
     *
     * Stamped Manual because moving it is a direct human choice, and written
     * row by row without the planned-group fan-out, as applyCategoryToSelection()
     * does: the fan-out would rewrite siblings the user never saw. One
     * transaction, so the user's agreement lands for every row or for none.
     *
     * The consented rows are locked, in id order, before the recompute reads
     * them, so a concurrent edit cannot slip in between the check and the save.
     *
     * @param  list<int>  $consentedIds
     *
     * @throws Throwable
     */
    public function moveManualContradictions(Transaction $source, int $categoryId, ?string $matchValue, array $consentedIds): void
    {
        $source->getConnection()->transaction(function () use ($source, $categoryId, $matchValue, $consentedIds): void {
            $locked = Transaction::query()
                ->where('user_id', $source->user_id)
                ->whereKey($consentedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->flip();

            foreach ($this->manualContradictions($source, $categoryId, $matchValue) as $transaction) {
                if (! $locked->has($transaction->id)) {
                    continue;
                }

                $transaction->category_id = $categoryId;
                $transaction->category_source = CategorySource::Manual;
                $transaction->propagateCategoryChange = false;
                $transaction->save();
            }
        });
    }

    /**
     * The default "description contains" value offered in the UI when the user
     * opts into categorising matching transactions. Prefers a clean merchant
     * name (bank feed), otherwise the most distinctive payee token of the
     * description. The user can edit it before the rule is applied.
     */
    public function suggestMatchValue(Transaction $source): string
    {
        if ($source->merchant_name !== null && $source->merchant_name !== '') {
            return $source->merchant_name;
        }

        $identity = $source->identityCleanDescription();

        if ($identity !== null && $identity !== '') {
            return $this->merchantToken($identity);
        }

        return $this->merchantToken($source->description);
    }

    /**
     * The trigger and actions a generated rule carries. Shared by the commit
     * path and the preview so the two cannot diverge.
     *
     * @return array{triggers: array<int, array<string, string>>, actions: array<int, array<string, string>>}
     */
    private function matchAttributes(Transaction $source, int $categoryId, ?string $matchValue, ?string $cleanDescription = null): array
    {
        $actions = [[
            'type' => RuleActionType::SetCategory->value,
            'value' => (string) $categoryId,
        ]];

        $cleanDescription = $cleanDescription !== null ? mb_trim($cleanDescription) : '';

        if ($cleanDescription !== '') {
            $actions[] = [
                'type' => RuleActionType::SetCleanDescription->value,
                'value' => $cleanDescription,
            ];
        }

        return [
            'triggers' => [$this->buildTrigger($source, $matchValue)],
            'actions' => $actions,
        ];
    }

    private function resolvedMatchValue(Transaction $source, ?string $matchValue): string
    {
        return $this->buildTrigger($source, $matchValue)['value'];
    }

    /** @return array<string, string> */
    private function buildTrigger(Transaction $source, ?string $matchValue = null): array
    {
        $value = $matchValue !== null ? mb_trim($matchValue) : '';

        if ($value !== '') {
            return [
                'field' => RuleTriggerField::Description->value,
                'operator' => RuleTriggerOperator::Contains->value,
                'value' => $value,
            ];
        }

        if ($source->merchant_name !== null && $source->merchant_name !== '') {
            return [
                'field' => RuleTriggerField::MerchantName->value,
                'operator' => RuleTriggerOperator::Equals->value,
                'value' => $source->merchant_name,
            ];
        }

        $identity = $source->identityCleanDescription();

        if ($identity !== null && $identity !== '') {
            return [
                'field' => RuleTriggerField::CleanDescription->value,
                'operator' => RuleTriggerOperator::Contains->value,
                'value' => $this->merchantToken($identity),
            ];
        }

        return [
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => $this->merchantToken($source->description),
        ];
    }

    /**
     * The longest non-generic payee token of the merchant signature, used as a
     * case-insensitive `contains` value so the trigger matches the source and
     * its siblings even when reference numbers vary. Leading payment-network /
     * method tokens (VISA, EFTPOS, …) are skipped and the longest remaining
     * token is the most distinctive payee word ("NETFLIX.COM"), so the rule
     * keys on the merchant rather than the card network or a short noise word.
     */
    private function merchantToken(string $raw): string
    {
        $tokens = explode(' ', MerchantSignature::for($raw));

        $candidates = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, self::GENERIC_TOKENS, true),
        ));

        if ($candidates === []) {
            return $tokens[0];
        }

        $best = $candidates[0];

        foreach ($candidates as $candidate) {
            if (mb_strlen($candidate) > mb_strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function ruleName(Transaction $source): string
    {
        $label = $source->merchant_name
            ?? $source->clean_description
            ?? $source->description;

        return mb_substr('Categorise '.$label, 0, 255);
    }

    private function group(int $userId): UserRuleGroup
    {
        $existing = UserRuleGroup::query()
            ->where('user_id', $userId)
            ->where('name', self::GROUP_NAME)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return UserRuleGroup::query()->create([
            'user_id' => $userId,
            'name' => self::GROUP_NAME,
            'order' => (int) UserRuleGroup::query()->where('user_id', $userId)->max('order') + 1,
            'is_active' => true,
            'stop_processing' => false,
        ]);
    }

    private function nextRuleOrder(int $groupId): int
    {
        return (int) UserRule::query()->where('user_rule_group_id', $groupId)->max('order') + 1;
    }
}
