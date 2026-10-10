<?php

declare(strict_types=1);

namespace App\Support\Budget;

use App\Enums\AccountClass;
use App\Enums\BudgetTag;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;

/**
 * Resolves the Needs/Wants/Savings tag for a category or a transaction.
 *
 * forCategory order: payee override, the user's override on the ROOT category, the root
 * category's own tag, then null. Child categories carry no tag and inherit the root's.
 * Only the given user's overrides are read.
 *
 * forTransaction decision table (a transaction that is not a paired transfer resolves
 * through forCategory; Income, Transfer and Balance roots are untagged, so they give null):
 *
 * | Row                                         | Other leg                              | Result  |
 * |---------------------------------------------|----------------------------------------|---------|
 * | debit on a tracked account                  | untracked Savings/Investment/TermDeposit | Savings |
 * | debit on a tracked account                  | untracked CreditCard that the payment leaves with nothing owed | Savings |
 * | debit on a tracked account                  | untracked CreditCard still owing       | null    |
 * | debit on a tracked account                  | any other untracked account            | null    |
 * | credit leg (money coming in)                | any                                    | null    |
 * | tracked <-> tracked                         | tracked                                | null    |
 * | untracked <-> untracked                     | untracked                              | null    |
 *
 * Only the debit leg of a tracked -> untracked pair is tagged, matching what
 * Transaction::scopeCountable counts as spend, so a transfer is never tagged twice. A
 * credit card on a tracked account nets to zero against the tracked leg and gives null.
 */
final class BudgetTagResolver
{
    public function forCategory(User $user, ?Category $category, ?Payee $payee = null): ?BudgetTag
    {
        if ($payee?->budget_tag !== null) {
            return $payee->budget_tag;
        }

        if ($category === null) {
            return null;
        }

        $root = $this->rootOf($category);

        $override = $user->categoryBudgetTags()
            ->where('category_id', $root->id)
            ->first();

        return $override->budget_tag ?? $root->budget_tag;
    }

    public function forTransaction(User $user, Transaction $transaction): ?BudgetTag
    {
        if ($transaction->transfer_pair_id !== null) {
            return $this->forTransfer($transaction);
        }

        return $this->forCategory($user, $transaction->category);
    }

    public function rootOf(Category $category): Category
    {
        $root = $category;

        while ($root->parent_id !== null && $root->parent !== null) {
            $root = $root->parent;
        }

        return $root;
    }

    private function forTransfer(Transaction $transaction): ?BudgetTag
    {
        $source = $transaction->account;
        $destination = $transaction->transferPair?->account;

        if ($destination === null
            || $transaction->direction !== TransactionDirection::Debit
            || ! $source->is_tracked
            || $destination->is_tracked) {
            return null;
        }

        return match ($destination->type) {
            AccountClass::Savings, AccountClass::Investment, AccountClass::TermDeposit => BudgetTag::Savings,
            AccountClass::CreditCard => $this->clearsCard($destination) ? BudgetTag::Savings : null,
            default => null,
        };
    }

    private function clearsCard(Account $card): bool
    {
        return $card->amountOwed() === 0;
    }
}
