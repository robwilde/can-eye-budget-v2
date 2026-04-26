<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class MonthlyProjectionService
{
    public function forUser(User $user, int $monthsAhead = 12): ?BalanceProjection
    {
        $primaryAccount = $user->primaryAccount;

        if ($primaryAccount === null) {
            return null;
        }

        $today = CarbonImmutable::today();
        $endDate = $today->addMonthsNoOverflow($monthsAhead);

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->excludingTransfers()
            ->where('start_date', '<=', $endDate)
            ->where(static function ($query) use ($today): void {
                $query->whereNull('until_date')->orWhere('until_date', '>=', $today);
            })
            ->get();

        $dailyBuckets = [];

        foreach ($plannedTransactions as $planned) {
            $signedAmount = $planned->direction === TransactionDirection::Credit
                ? abs((int) $planned->amount)
                : -abs((int) $planned->amount);

            foreach ($planned->occurrencesBetween($today, $endDate) as $occurrence) {
                $key = $occurrence->format('Y-m-d');

                if (! isset($dailyBuckets[$key])) {
                    $dailyBuckets[$key] = [
                        'date' => $occurrence,
                        'netCents' => 0,
                        'descriptions' => [],
                    ];
                }

                $dailyBuckets[$key]['netCents'] += $signedAmount;
                $dailyBuckets[$key]['descriptions'][] = $planned->description;
            }
        }

        ksort($dailyBuckets);

        $startingBalanceCents = (int) $primaryAccount->balance;
        $runningBalance = $startingBalanceCents;
        $points = [
            new BalancePoint(
                date: $today,
                balanceCents: $runningBalance,
                eventDescription: 'Starting balance',
                eventAmountCents: 0,
            ),
        ];
        $firstNegativeDate = null;

        foreach ($dailyBuckets as $bucket) {
            $runningBalance += $bucket['netCents'];
            $count = count($bucket['descriptions']);
            $description = $count === 1 ? $bucket['descriptions'][0] : sprintf('%d events', $count);

            $points[] = new BalancePoint(
                date: $bucket['date'],
                balanceCents: $runningBalance,
                eventDescription: $description,
                eventAmountCents: $bucket['netCents'],
            );

            if ($firstNegativeDate === null && $runningBalance < 0) {
                $firstNegativeDate = $bucket['date'];
            }
        }

        return new BalanceProjection(
            startingBalanceCents: $startingBalanceCents,
            startsAt: $today,
            points: $points,
            firstNegativeDate: $firstNegativeDate,
        );
    }
}
