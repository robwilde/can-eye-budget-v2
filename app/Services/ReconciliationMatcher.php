<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlannedTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class ReconciliationMatcher
{
    public const float AMOUNT_TOLERANCE = 0.10;

    public const int DATE_TOLERANCE_DAYS = 3;

    /**
     * @return Collection<int, Transaction>
     */
    public function findSuggestions(PlannedTransaction $planned, CarbonImmutable $occurrenceDate): Collection
    {
        $dateFrom = $occurrenceDate->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $occurrenceDate->addDays(self::DATE_TOLERANCE_DAYS);
        $minAmount = (int) floor($planned->amount * (1 - self::AMOUNT_TOLERANCE));
        $maxAmount = (int) ceil($planned->amount * (1 + self::AMOUNT_TOLERANCE));

        return Transaction::query()
            ->where('user_id', $planned->user_id)
            ->current()
            ->where('account_id', $planned->account_id)
            ->where('direction', $planned->direction)
            ->whereNull('planned_transaction_id')
            ->whereBetween('post_date', [$dateFrom, $dateTo])
            ->whereRaw('ABS(amount) BETWEEN ? AND ?', [$minAmount, $maxAmount])
            ->with('account:id,name')
            ->get()
            ->sortBy([
                fn (Transaction $a, Transaction $b) => abs($a->post_date->diffInDays($occurrenceDate)) <=> abs($b->post_date->diffInDays($occurrenceDate)),
                fn (Transaction $a, Transaction $b) => abs(abs($a->amount) - abs($planned->amount)) <=> abs(abs($b->amount) - abs($planned->amount)),
            ])
            ->values();
    }

    public function findLinkedForOccurrence(PlannedTransaction $planned, CarbonImmutable $occurrenceDate): ?Transaction
    {
        $dateFrom = $occurrenceDate->subDays(self::DATE_TOLERANCE_DAYS);
        $dateTo = $occurrenceDate->addDays(self::DATE_TOLERANCE_DAYS);

        return Transaction::query()
            ->where('user_id', $planned->user_id)
            ->current()
            ->where('planned_transaction_id', $planned->id)
            ->whereBetween('post_date', [$dateFrom, $dateTo])
            ->with('account:id,name')
            ->first();
    }

    public function link(Transaction $transaction, PlannedTransaction $planned): void
    {
        $transaction->update(['planned_transaction_id' => $planned->id]);
    }

    public function unlink(Transaction $transaction): void
    {
        $transaction->update(['planned_transaction_id' => null]);
    }
}
