<?php

declare(strict_types=1);

namespace App\Services\Projection;

use App\Enums\TransactionDirection;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\OccurrenceSchedule;

final readonly class MonthlyProjectionService
{
    public function forUser(User $user, int $monthsAhead = 12): ?BalanceProjection
    {
        $primaryAccount = $user->primaryAccount;

        if ($primaryAccount === null) {
            return null;
        }

        $today = $user->today();
        $endDate = $today->addMonthsNoOverflow($monthsAhead);

        $plannedTransactions = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->countable()
            ->with(['account:id,is_tracked,exclude_from_reports', 'transferToAccount:id,is_tracked,exclude_from_reports'])
            ->where('start_date', '<=', $endDate)
            ->where(static function ($query) use ($today): void {
                $query->whereNull('until_date')->orWhere('until_date', '>=', $today);
            })
            ->get();

        $dailyBuckets = [];

        $occurrencesByPlan = (new OccurrenceSchedule)->between($plannedTransactions->collect(), $today, $endDate);

        foreach ($plannedTransactions as $planned) {
            $direction = $planned->countedDirection();

            if ($direction === null || $planned->countedAccount()?->exclude_from_reports !== false) {
                continue;
            }

            foreach ($occurrencesByPlan[$planned->id] as $occurrence) {
                $key = $occurrence->date->format('Y-m-d');

                if (! isset($dailyBuckets[$key])) {
                    $dailyBuckets[$key] = [
                        'date' => $occurrence->date,
                        'netCents' => 0,
                        'descriptions' => [],
                    ];
                }

                $dailyBuckets[$key]['netCents'] += $direction === TransactionDirection::Credit ? $occurrence->amount : -$occurrence->amount;
                $dailyBuckets[$key]['descriptions'][] = $planned->description;
            }
        }

        ksort($dailyBuckets);

        $startingBalanceCents = $primaryAccount->availableBalance();
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
