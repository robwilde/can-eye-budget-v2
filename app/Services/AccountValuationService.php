<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountClass;
use App\Enums\TransactionStatus;
use App\Enums\ValuationKind;
use App\Exceptions\InvalidValuationException;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Transaction;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes the stored balance valuations of an account. Every public method is one
 * DB transaction that locks the account row first, so concurrent writes serialise,
 * and rebuilds the account's daily balance history inside that same transaction.
 */
final class AccountValuationService
{
    private const int MAX_YEARS_BACK = 30;

    private const int DEFAULT_OPENING_YEARS_BACK = 2;

    public function __construct(private readonly AccountBalanceHistoryBuilder $history) {}

    /**
     * Sets or moves the opening balance. Without a date it is the earlier of two years ago
     * and the day before the oldest other entry.
     */
    public function setOpeningBalance(Account $account, int $balance, ?CarbonImmutable $on = null): AccountValuation
    {
        return DB::transaction(function () use ($account, $balance, $on): AccountValuation {
            $account = $this->lock($account);
            $existing = $this->openingValuation($account);
            $oldest = $this->oldestEntryDate($account);
            $date = $on?->startOfDay() ?? $this->defaultOpeningDate($oldest);

            $this->assertNotTooFarBack($date);

            if ($oldest !== null && ! $date->lessThan($oldest)) {
                throw InvalidValuationException::openingNotBeforeOldestEntry();
            }

            $valuation = $existing ?? new AccountValuation(['account_id' => $account->id, 'kind' => ValuationKind::OpeningBalance]);
            $valuation->fill(['valued_on' => $date, 'balance' => $balance, 'difference' => null])->save();

            $this->syncAccount($account);

            return $valuation;
        });
    }

    /**
     * Records a known balance on a date. A date that already has a valuation updates it.
     */
    public function reconcile(Account $account, int $balance, CarbonImmutable $on, ?string $notes = null): AccountValuation
    {
        return DB::transaction(function () use ($account, $balance, $on, $notes): AccountValuation {
            $account = $this->lock($account);
            $date = $on->startOfDay();
            $existing = $account->valuations()->whereDate('valued_on', $date)->first();

            $this->assertReconcilable($account, $date, $existing);

            $valuation = $existing ?? new AccountValuation(['account_id' => $account->id, 'kind' => ValuationKind::Reconciliation]);
            $valuation->fill([
                'valued_on' => $date,
                'balance' => $balance,
                'difference' => $balance - $this->balanceOn($account, $date, inclusive: false, excluding: $existing),
                'notes' => $notes ?? $existing?->notes,
            ])->save();

            $this->syncAccount($account);

            return $valuation;
        });
    }

    /**
     * Sets today's balance. A cash or credit-card account with no reconciliations shifts its
     * opening balance by the difference; any other account gets a reconciliation dated today.
     */
    public function setCurrentBalance(Account $account, int $balance, ?CarbonImmutable $today = null): AccountValuation
    {
        return DB::transaction(function () use ($account, $balance, $today): AccountValuation {
            $account = $this->lock($account);
            $today = $today?->startOfDay() ?? CarbonImmutable::today();

            if (! $this->shiftsOpeningBalance($account)) {
                return $this->reconcile($account, $balance, $today);
            }

            $opening = $this->openingValuation($account);

            if ($opening === null) {
                return $this->setOpeningBalance($account, $balance - $this->signedTransactionSum($account, null, $today));
            }

            return $this->setOpeningBalance(
                $account,
                $opening->balance + ($balance - $this->balanceOn($account, $today, inclusive: true)),
                $opening->valued_on,
            );
        });
    }

    public function update(AccountValuation $valuation, int $balance, ?CarbonImmutable $on = null, ?string $notes = null): AccountValuation
    {
        return DB::transaction(function () use ($valuation, $balance, $on, $notes): AccountValuation {
            $account = $this->lock($valuation->account);
            $valuation = $account->valuations()->findOrFail($valuation->id);
            $date = $on?->startOfDay() ?? $valuation->valued_on;

            if ($valuation->kind === ValuationKind::OpeningBalance) {
                return $this->setOpeningBalance($account, $balance, $date);
            }

            $other = $account->valuations()->whereDate('valued_on', $date)->whereKeyNot($valuation->id)->exists();

            if ($other) {
                throw InvalidValuationException::dateTaken();
            }

            $this->assertReconcilable($account, $date, null);

            $valuation->fill([
                'valued_on' => $date,
                'balance' => $balance,
                'difference' => $balance - $this->balanceOn($account, $date, inclusive: false, excluding: $valuation),
                'notes' => $notes ?? $valuation->notes,
            ])->save();

            $this->syncAccount($account);

            return $valuation;
        });
    }

    public function delete(AccountValuation $valuation): void
    {
        DB::transaction(function () use ($valuation): void {
            $account = $this->lock($valuation->account);

            $account->valuations()->whereKey($valuation->id)->delete();

            $this->syncAccount($account, clearSummary: true);
        });
    }

    /**
     * The balance at the end of a date. It is anchored on the latest valuation on or before the
     * date, plus the posted transactions after it. With none before, it works backwards from the
     * nearest later valuation by reversing the transactions in between. An account with no
     * valuation at all keeps its stored balance.
     */
    public function balanceOn(Account $account, CarbonImmutable $date, bool $inclusive = true, ?AccountValuation $excluding = null): int
    {
        $others = $account->valuations()
            ->when($excluding !== null, fn ($query) => $query->whereKeyNot($excluding->id));

        $anchor = (clone $others)
            ->whereDate('valued_on', $inclusive ? '<=' : '<', $date)
            ->orderByDesc('valued_on')
            ->first();

        if ($anchor !== null) {
            return $anchor->balance + $this->signedTransactionSum($account, $anchor->valued_on, $date);
        }

        $later = (clone $others)->orderBy('valued_on')->first();

        if ($later === null) {
            return $account->balance - $this->signedTransactionSum($account, $date, CarbonImmutable::today());
        }

        return $later->balance - $this->signedTransactionSum($account, $date, $later->valued_on);
    }

    private function lock(Account $account): Account
    {
        return Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
    }

    private function openingValuation(Account $account): ?AccountValuation
    {
        return $account->valuations()->where('kind', ValuationKind::OpeningBalance)->first();
    }

    private function shiftsOpeningBalance(Account $account): bool
    {
        $isCashOrCard = in_array($account->type, [AccountClass::Transaction, AccountClass::CreditCard], true);

        return $isCashOrCard
            && ! $account->valuations()->where('kind', ValuationKind::Reconciliation)->exists();
    }

    /**
     * Oldest dated entry other than the opening balance: a current, non-deleted transaction
     * or another valuation.
     */
    private function oldestEntryDate(Account $account): ?CarbonImmutable
    {
        $transaction = Transaction::query()->current()->where('account_id', $account->id)->min('post_date');
        $valuation = $account->valuations()->where('kind', '!=', ValuationKind::OpeningBalance)->min('valued_on');

        $dates = array_filter([$transaction, $valuation]);

        if ($dates === []) {
            return null;
        }

        return CarbonImmutable::parse(min($dates))->startOfDay();
    }

    private function defaultOpeningDate(?CarbonImmutable $oldest): CarbonImmutable
    {
        $default = CarbonImmutable::today()->subYears(self::DEFAULT_OPENING_YEARS_BACK);

        return $oldest !== null && $oldest->subDay()->lessThan($default) ? $oldest->subDay() : $default;
    }

    private function assertNotTooFarBack(CarbonImmutable $date): void
    {
        if ($date->lessThanOrEqualTo(CarbonImmutable::today()->subYears(self::MAX_YEARS_BACK))) {
            throw InvalidValuationException::tooFarBack();
        }
    }

    private function assertReconcilable(Account $account, CarbonImmutable $date, ?AccountValuation $existing): void
    {
        $this->assertNotTooFarBack($date);

        if ($existing?->kind === ValuationKind::OpeningBalance) {
            throw InvalidValuationException::dateTaken();
        }

        $opening = $this->openingValuation($account);

        if ($opening !== null && $date->lessThanOrEqualTo($opening->valued_on)) {
            throw InvalidValuationException::notAfterOpeningBalance();
        }
    }

    /**
     * Credits minus debits of the account's current, posted, non-deleted transactions
     * dated after $after (exclusive) up to $until (inclusive).
     */
    private function signedTransactionSum(Account $account, ?CarbonImmutable $after, CarbonImmutable $until): int
    {
        return (int) Transaction::query()
            ->current()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Posted)
            ->when($after !== null, fn ($query) => $query->whereDate('post_date', '>', $after))
            ->whereDate('post_date', '<=', $until)
            ->selectRaw("coalesce(sum(case when direction = 'credit' then amount else -amount end), 0) as total")
            ->value('total');
    }

    /**
     * A reconciliation's difference is its balance minus what the account implied just before
     * it, which changes when an earlier valuation is added, moved or removed. The account's
     * first valuation keeps the difference it was written with: nothing earlier anchors it.
     */
    private function refreshDifferences(Account $account): void
    {
        $valuations = $account->valuations()->orderBy('valued_on')->get();
        $first = $valuations->first();

        foreach ($valuations as $valuation) {
            if ($valuation->kind !== ValuationKind::Reconciliation || $valuation->is($first)) {
                continue;
            }

            $difference = $valuation->balance - $this->balanceOn($account, $valuation->valued_on, inclusive: false, excluding: $valuation);

            if ($difference !== $valuation->difference) {
                $valuation->forceFill(['difference' => $difference])->save();
            }
        }
    }

    /**
     * Keeps the account's last-reconcile summary in step with its latest reconciliation and,
     * for manually kept balances, its stored balance with today's derived balance.
     */
    private function syncAccount(Account $account, bool $clearSummary = false): void
    {
        $this->refreshDifferences($account);

        $latest = $account->valuations()
            ->where('kind', ValuationKind::Reconciliation)
            ->orderByDesc('valued_on')
            ->first();

        $changes = [];

        if ($latest !== null || $clearSummary) {
            $changes['reconciled_on'] = $latest?->valued_on?->toDateString();
            $changes['reconcile_difference'] = $latest?->difference;
        }

        $manual = $account->isManuallyBalanced();

        if ($manual && $account->valuations()->exists()) {
            $changes['balance'] = $this->balanceOn($account, CarbonImmutable::today());
            $changes['balance_updated_at'] = CarbonImmutable::now();
        }

        $account->forceFill($changes)->save();

        $this->history->rebuild($account);
    }
}
