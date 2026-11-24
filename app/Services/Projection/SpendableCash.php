<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\Enums\AccountClass;
use App\Models\Account;
use App\Models\User;
use App\Services\CurrencyConverter;
use Carbon\CarbonImmutable;

/**
 * The cash the bill planners measure against (not the budget preview's CashOnHandCalculator, which nets off goal earmarks and adds term deposits and foreign accounts): the balances of the user's active, tracked Transaction and Savings accounts
 * (credit cards are debt, not cash), converted to the base currency when multi-currency is on. Null when the user
 * has no such account, because then nothing is known.
 */
final readonly class SpendableCash
{
    /** @return array{total: int, accountIds: list<int>}|null */
    public function for(User $user, CarbonImmutable $today): ?array
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->active()
            ->tracked()
            ->whereIn('type', [AccountClass::Transaction->value, AccountClass::Savings->value])
            ->get(['id', 'balance', 'currency']);

        if ($accounts->isEmpty()) {
            return null;
        }

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;

        return [
            'total' => (int) $accounts->sum(fn (Account $account): int => $converter === null ? $account->balance : $converter->convert($account->balance, $account->currency, $user->base_currency, $today)),
            'accountIds' => $accounts->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }
}
