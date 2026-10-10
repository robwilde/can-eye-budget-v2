<?php

declare(strict_types=1);

namespace App\Services\Payees;

use App\Enums\BudgetTag;
use App\Enums\CategorySource;
use App\Enums\PayeeStatus;
use App\Enums\RuleActionType;
use App\Enums\RuleTriggerField;
use App\Enums\RuleTriggerOperator;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;
use App\Models\UserRuleGroup;
use App\Services\CategoryRuleMiner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records the user's answer for a payee: one rule, and the history moved to match.
 *
 * One answer is one rule plus a Manual re-categorisation of the payee's existing
 * non-transfer, non-split debits, written in a single transaction. Asking again
 * updates the same rule instead of adding another. Rows the user already categorised
 * by hand stay where they put them, so a later answer never contradicts an earlier
 * deliberate choice. Confirmed and dismissed payees are never sent to Jev again.
 */
final class PayeeConfirmer
{
    /**
     * @throws InvalidArgumentException when the category is not one the user can choose
     */
    public function confirm(User $user, Payee $payee, int $categoryId, ?BudgetTag $tagOverride = null): Payee
    {
        return DB::transaction(function () use ($user, $payee, $categoryId, $tagOverride): Payee {
            $locked = $this->owned($user, $payee);

            throw_unless(
                Category::visibleSortedByFullPath()->contains('id', $categoryId),
                InvalidArgumentException::class,
                'The category is not available.',
            );

            $rule = $this->ruleFor($user, $locked, $categoryId);

            $this->updateTransactionsForMerchantKey($user, $locked->merchant_key, $categoryId);

            $locked->update([
                'status' => PayeeStatus::Confirmed,
                'confirmed_category_id' => $categoryId,
                'budget_tag' => $tagOverride,
                'user_rule_id' => $rule->id,
                'resolved_at' => now(),
            ]);

            return $locked;
        });
    }

    public function dismiss(User $user, Payee $payee): Payee
    {
        return DB::transaction(function () use ($user, $payee): Payee {
            $locked = $this->owned($user, $payee);

            $locked->update([
                'status' => PayeeStatus::Dismissed,
                'resolved_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Accept Jev's suggestion for each of the user's pending payees that has one.
     *
     * @param  list<int>  $payeeIds
     * @return int how many payees were confirmed
     */
    public function confirmMany(User $user, array $payeeIds): int
    {
        $confirmed = 0;

        $payees = Payee::query()
            ->where('user_id', $user->id)
            ->pending()
            ->whereIn('id', $payeeIds)
            ->whereNotNull('suggested_category_id')
            ->orderBy('id')
            ->get();

        foreach ($payees as $payee) {
            try {
                $this->confirm($user, $payee, (int) $payee->suggested_category_id);
                $confirmed++;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $confirmed;
    }

    /**
     * Update transactions for a merchant key with the same guards used by confirm():
     * - user-scoped
     * - current (non-superseded) debits only
     * - excluding transfers and splits
     * - respecting existing Manual categorisations.
     */
    public function updateTransactionsForMerchantKey(User $user, string $merchantKey, int $categoryId): void
    {
        Transaction::query()
            ->where('user_id', $user->id)
            ->where('merchant_key', $merchantKey)
            ->where('direction', TransactionDirection::Debit)
            ->excludingTransfers()
            ->current()
            ->whereDoesntHave('splits')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('category_source')
                ->orWhere('category_source', '!=', CategorySource::Manual->value))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (Transaction $transaction) use ($categoryId): void {
                $transaction->category_id = $categoryId;
                $transaction->category_source = CategorySource::Manual;
                $transaction->propagateCategoryChange = false;
                $transaction->save();
            });
    }

    private function owned(User $user, Payee $payee): Payee
    {
        return Payee::query()
            ->where('user_id', $user->id)
            ->whereKey($payee->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ruleFor(User $user, Payee $payee, int $categoryId): UserRule
    {
        $actions = [[
            'type' => RuleActionType::SetCategory->value,
            'value' => (string) $categoryId,
        ]];

        $existing = $payee->user_rule_id === null
            ? null
            : UserRule::query()->where('user_id', $user->id)->whereKey($payee->user_rule_id)->first();

        if ($existing !== null) {
            $existing->update(['actions' => $actions, 'is_active' => true]);

            return $existing;
        }

        $group = $this->group($user);

        return UserRule::query()->create([
            'user_id' => $user->id,
            'user_rule_group_id' => $group->id,
            'name' => mb_substr('Categorise '.$payee->merchant_name, 0, 255),
            'triggers' => [
                [
                    'field' => RuleTriggerField::MerchantKey->value,
                    'operator' => RuleTriggerOperator::Equals->value,
                    'value' => $payee->merchant_key,
                ],
                [
                    'field' => RuleTriggerField::Direction->value,
                    'operator' => RuleTriggerOperator::Is->value,
                    'value' => TransactionDirection::Debit->value,
                ],
            ],
            'actions' => $actions,
            'strict_mode' => true,
            'is_auto_apply' => true,
            'is_active' => true,
            'order' => (int) UserRule::query()->where('user_rule_group_id', $group->id)->max('order') + 1,
        ]);
    }

    private function group(User $user): UserRuleGroup
    {
        return UserRuleGroup::query()
            ->where('user_id', $user->id)
            ->where('name', CategoryRuleMiner::GROUP_NAME)
            ->first()
            ?? UserRuleGroup::query()->create([
                'user_id' => $user->id,
                'name' => CategoryRuleMiner::GROUP_NAME,
                'order' => (int) UserRuleGroup::query()->where('user_id', $user->id)->max('order') + 1,
                'is_active' => true,
                'stop_processing' => false,
            ]);
    }
}
