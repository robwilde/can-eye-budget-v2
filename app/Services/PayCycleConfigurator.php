<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayFrequency;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single write path for configuring a user's pay cycle.
 *
 * Every entry point that sets a pay cycle — the settings page, accepting an
 * analysis suggestion, and the auto-apply pipeline stage — routes through here
 * so the user's pay columns and the recurring income planned transaction that
 * drives the balance projection always stay in sync.
 */
final class PayCycleConfigurator
{
    /**
     * Persist the pay cycle on the user and upsert the matching recurring
     * income planned transaction.
     *
     * The income row is keyed on `is_pay_cycle_income` so re-applying updates
     * the existing row instead of creating duplicates. When the user has no
     * primary account yet the pay columns are still saved, but the planned
     * transaction is skipped because `planned_transactions.account_id` is NOT
     * NULL.
     *
     * @throws Throwable
     */
    public function apply(
        User $user,
        int $payAmountCents,
        PayFrequency $frequency,
        CarbonImmutable|string $nextPayDate,
        ?string $description = null,
    ): void {
        DB::transaction(function () use ($user, $payAmountCents, $frequency, $nextPayDate, $description): void {
            $user->update([
                'pay_amount' => $payAmountCents,
                'pay_frequency' => $frequency,
                'next_pay_date' => $nextPayDate,
            ]);

            if ($user->primary_account_id === null) {
                return;
            }

            PlannedTransaction::updateOrCreate(
                ['user_id' => $user->id, 'is_pay_cycle_income' => true],
                [
                    'account_id' => $user->primary_account_id,
                    'category_id' => $this->salaryCategoryId(),
                    'amount' => $payAmountCents,
                    'direction' => TransactionDirection::Credit,
                    'description' => $description ?? 'Salary',
                    'start_date' => $nextPayDate,
                    'frequency' => $frequency->toRecurrenceFrequency(),
                    'until_date' => null,
                    'is_active' => true,
                ],
            );
        });
    }

    /**
     * Best-effort lookup of the seeded "Income › Salary" category. Returns null
     * when the category tree has not been seeded, leaving the income planned
     * transaction uncategorised (the column is nullable).
     */
    private function salaryCategoryId(): ?int
    {
        return Category::query()
            ->where('name', 'Salary')
            ->whereHas('parent', fn (Builder $query): Builder => $query->where('name', 'Income'))
            ->value('id');
    }
}
