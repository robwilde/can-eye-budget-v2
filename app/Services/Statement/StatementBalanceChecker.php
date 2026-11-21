<?php

declare(strict_types=1);

namespace App\Services\Statement;

use App\DTOs\StatementBalanceCheck;
use App\Models\AccountDailyBalance;
use App\Models\Statement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compares a statement's opening balance, closing balance and period movement with the
 * account's daily balance history. The ledger value for the opening balance is the start of
 * the period's first day; for the closing balance, the end of its last day.
 */
final class StatementBalanceChecker
{
    public const int TOLERANCE_CENTS = 1;

    public function check(Statement $statement): StatementBalanceCheck
    {
        if ($statement->account_id === null || $statement->period_start === null || $statement->period_end === null) {
            return StatementBalanceCheck::notChecked();
        }

        $rows = AccountDailyBalance::query()
            ->forUser($statement->user_id)
            ->where('account_id', $statement->account_id)
            ->where(fn (Builder $dates): Builder => $dates
                ->whereDate('balance_date', $statement->period_start->toDateString())
                ->orWhereDate('balance_date', $statement->period_end->toDateString()))
            ->get()
            ->keyBy(static fn (AccountDailyBalance $row): string => $row->balance_date->toDateString());

        $first = $rows->get($statement->period_start->toDateString());
        $last = $rows->get($statement->period_end->toDateString());

        $failures = [];
        $compared = false;

        foreach ([['opening', $statement->opening_balance, $first?->start_balance, $first], ['closing', $statement->closing_balance, $last?->end_balance, $last]] as [$name, $statementValue, $ledgerValue, $row]) {
            if ($statementValue === null || $ledgerValue === null || ! $this->sameCurrency($statement, $row)) {
                continue;
            }

            $compared = true;

            if (abs($statementValue - $ledgerValue) > self::TOLERANCE_CENTS) {
                $failures[] = $name;
            }
        }

        if ($statement->opening_balance !== null && $statement->closing_balance !== null && $first !== null && $last !== null
            && $this->sameCurrency($statement, $first) && $this->sameCurrency($statement, $last)) {
            $compared = true;

            if (abs(($statement->closing_balance - $statement->opening_balance) - ($last->end_balance - $first->start_balance)) > self::TOLERANCE_CENTS) {
                $failures[] = 'movement';
            }
        }

        return $compared ? new StatementBalanceCheck(true, $failures) : StatementBalanceCheck::notChecked();
    }

    private function sameCurrency(Statement $statement, ?AccountDailyBalance $row): bool
    {
        return $row !== null && ($statement->currency === null || $statement->currency === $row->currency);
    }
}
