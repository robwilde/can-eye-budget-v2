<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Models\Transaction;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Support\Recurring\MerchantSignature;

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
     * they must never become the merchant token — otherwise the generated rule
     * matches every card transaction instead of the merchant.
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
        private RuleActionExecutor $executor,
    ) {}

    public function generateAndApply(Transaction $source, int $categoryId): UserRule
    {
        $group = $this->group($source->user_id);

        $rule = UserRule::query()->create([
            'user_id' => $source->user_id,
            'user_rule_group_id' => $group->id,
            'name' => $this->ruleName($source),
            'triggers' => [$this->buildTrigger($source)],
            'actions' => [[
                'type' => RuleActionType::SetCategory->value,
                'value' => (string) $categoryId,
            ]],
            'strict_mode' => true,
            'is_auto_apply' => true,
            'is_active' => true,
            'order' => $this->nextRuleOrder($group->id),
        ]);

        $this->applyToExisting($source->user_id, $rule);

        return $rule;
    }

    private function applyToExisting(int $userId, UserRule $rule): void
    {
        // Stream by id rather than loading the whole history into memory. Keying
        // on the (unchanging) id keeps paging stable even though we mutate rows.
        Transaction::query()
            ->where('user_id', $userId)
            ->current()
            ->lazyById()
            ->each(function (Transaction $transaction) use ($rule): void {
                if ($this->evaluator->matches($transaction, $rule)) {
                    $this->executor->execute($transaction, $rule->actions);
                }
            });
    }

    /** @return array<string, string> */
    private function buildTrigger(Transaction $source): array
    {
        if ($source->merchant_name !== null && $source->merchant_name !== '') {
            return [
                'field' => RuleTriggerField::MerchantName->value,
                'operator' => RuleTriggerOperator::Equals->value,
                'value' => $source->merchant_name,
            ];
        }

        if ($source->clean_description !== null && $source->clean_description !== '') {
            return [
                'field' => RuleTriggerField::CleanDescription->value,
                'operator' => RuleTriggerOperator::Contains->value,
                'value' => $this->merchantToken($source->clean_description),
            ];
        }

        return [
            'field' => RuleTriggerField::Description->value,
            'operator' => RuleTriggerOperator::Contains->value,
            'value' => $this->merchantToken($source->description),
        ];
    }

    /**
     * The first non-generic payee token of the merchant signature, used as a
     * case-insensitive `contains` value so the trigger matches the source and
     * its siblings even when reference numbers vary. Leading payment-network /
     * method tokens (VISA, EFTPOS, PAYPAL, …) are skipped so the rule keys on
     * the merchant ("NETFLIX.COM") instead of matching every card transaction.
     */
    private function merchantToken(string $raw): string
    {
        $tokens = explode(' ', MerchantSignature::for($raw));

        foreach ($tokens as $token) {
            if (! in_array($token, self::GENERIC_TOKENS, true)) {
                return $token;
            }
        }

        return $tokens[0];
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
