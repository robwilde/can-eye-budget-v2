<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountClass;
use App\Enums\GoalStatus;
use App\Models\Account;
use App\Models\GoalAccount;
use App\Models\User;

/**
 * Cash-account balances less what active and paused goals have earmarked on them. A goal that
 * claims a whole account earmarks its balance. This is a preview figure: it never feeds the
 * budget's allocation arithmetic. Every query is limited to the user's rows.
 */
final class CashOnHandCalculator
{
    private const array CASH_TYPES = [
        AccountClass::Transaction,
        AccountClass::Savings,
        AccountClass::TermDeposit,
        AccountClass::Foreign,
    ];

    public function for(User $user): int
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->active()
            ->tracked()
            ->whereIn('type', array_map(static fn (AccountClass $type): string => $type->value, self::CASH_TYPES))
            ->get(['id', 'balance', 'currency']);

        if ($accounts->isEmpty()) {
            return 0;
        }

        $links = GoalAccount::query()
            ->whereIn('account_id', $accounts->modelKeys())
            ->whereHas('goal', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereIn('status', [GoalStatus::Active->value, GoalStatus::Paused->value]))
            ->get(['account_id', 'earmark_amount'])
            ->groupBy('account_id');

        $converter = CurrencyConverter::enabled() ? app(CurrencyConverter::class) : null;
        $today = $user->today();
        $cash = 0;

        foreach ($accounts as $account) {
            $balance = $account->balance;
            $claimed = $links->get($account->id)?->sum(static fn (GoalAccount $link): int => $link->earmark_amount ?? max(0, $balance)) ?? 0;

            $net = $balance - min(max(0, $balance), $claimed);
            $cash += $converter === null ? $net : $converter->convert($net, $account->currency, $user->base_currency, $today);
        }

        return $cash;
    }
}
