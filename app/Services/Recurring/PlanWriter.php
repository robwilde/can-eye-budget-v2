<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\PlanRefusal;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\PlanWriteRefusedException;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates, changes and deletes the user's plans for the plan manager, the transaction modal and the REST API,
 * so the rules live in one place: refused while recurring is off, pay cycle income is never edited, a new
 * plan starts after the user's today and sits on a tracked account in a visible category, and no plan
 * repeats another series (checked on create, and on update when a field that identifies the series changes).
 * A category that was hidden after the plan was made stays on it until the category is changed.
 *
 * Callers parse their input into the plan's columns (cents, enums) and show a refusal in their own way.
 */
final readonly class PlanWriter
{
    public function __construct(private PlanDuplicateGuard $guard) {}

    /** The first day a new plan may start: the day after the user's today. */
    public function earliestStartDate(User $user): CarbonImmutable
    {
        return $user->today()->startOfDay()->addDay();
    }

    /** Why this user may not write plans, or this plan may not be written, or null. */
    public function refusalFor(User $user, ?PlannedTransaction $plan = null): ?PlanRefusal
    {
        if (! RecurringGate::enabled($user)) {
            return PlanRefusal::RecurringOff;
        }

        return $plan?->is_pay_cycle_income === true ? PlanRefusal::PayCycleIncome : null;
    }

    /**
     * @param  array<string, mixed>  $attributes  the plan's columns; user_id is always the user's
     * @param  int|null  $merchantId  the merchant the series is for, which also identifies it
     *
     * @throws PlanWriteRefusedException
     * @throws Throwable
     */
    public function create(User $user, array $attributes, ?int $merchantId = null): PlannedTransaction
    {
        $this->refuse($this->refusalFor($user));

        return DB::transaction(function () use ($user, $attributes, $merchantId): PlannedTransaction {
            $this->lockUser($user);

            $this->assertTrackedAccount($user, $attributes['account_id'] ?? null);
            $this->assertVisibleCategory($user, $attributes['category_id'] ?? null);
            $this->assertStartsAfterToday($user, $attributes['start_date'] ?? null);

            $transfer = ($attributes['transfer_to_account_id'] ?? null) !== null;

            $this->refuse(! $transfer && $this->guard->exists(
                $user,
                (int) $attributes['account_id'],
                (int) $attributes['amount'],
                $this->direction($attributes['direction']),
                $merchantId,
                (string) ($attributes['description'] ?? ''),
            ) ? PlanRefusal::Duplicate : null);

            return PlannedTransaction::query()->create([...$attributes, 'user_id' => $user->id]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  only the columns to change
     * @param  int|null  $merchantId  the merchant the series is for after the change
     *
     * @throws PlanWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function update(User $user, PlannedTransaction $plan, array $attributes, ?int $merchantId = null): PlannedTransaction
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($user, $plan, $attributes, $merchantId): PlannedTransaction {
            $this->lockUser($user);

            $current = PlannedTransaction::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($plan->id);

            $this->refuse($this->refusalFor($user, $current));

            if ($this->changes($attributes, $current, 'account_id')) {
                $this->assertTrackedAccount($user, $attributes['account_id']);
            }

            if ($this->changes($attributes, $current, 'category_id')) {
                $this->assertVisibleCategory($user, $attributes['category_id']);
            }

            $this->refuse($this->makesDuplicate($user, $current, $attributes, $merchantId) ? PlanRefusal::Duplicate : null);

            if (array_key_exists('frequency', $attributes)
                && $this->frequency($attributes['frequency']) !== $current->frequency
                && ! array_key_exists('days_of_month', $attributes)) {
                $attributes['days_of_month'] = null;
            }

            $current->update($attributes);

            return $current;
        });
    }

    /**
     * @throws PlanWriteRefusedException
     * @throws ModelNotFoundException
     * @throws Throwable
     */
    public function discard(User $user, PlannedTransaction $plan): void
    {
        DB::transaction(function () use ($user, $plan): void {
            $current = PlannedTransaction::query()->where('user_id', $user->id)->lockForUpdate()->findOrFail($plan->id);

            $this->refuse($this->refusalFor($user, $current));

            $current->discard();
        });
    }

    /**
     * Whether the change moves the plan onto another series: its account, direction, amount, merchant or
     * description, or it stops being a transfer. A plan that repeats another already is not refused for an
     * unrelated edit. A merchant counts as changed only when it resolves to other keys, so a plan still
     * carrying a merged merchant's old key is not moved by saving the surviving merchant's key.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makesDuplicate(User $user, PlannedTransaction $current, array $attributes, ?int $merchantId): bool
    {
        $transferTo = array_key_exists('transfer_to_account_id', $attributes) ? $attributes['transfer_to_account_id'] : $current->transfer_to_account_id;

        if ($transferTo !== null) {
            return false;
        }

        $account = (int) ($attributes['account_id'] ?? $current->account_id);
        $amount = abs((int) ($attributes['amount'] ?? $current->amount));
        $direction = $this->direction($attributes['direction'] ?? $current->direction);
        $description = (string) ($attributes['description'] ?? $current->description);
        $merchantKey = array_key_exists('merchant_key', $attributes) ? $attributes['merchant_key'] : $current->merchant_key;

        $keys = $this->guard->merchantKeys($user, $merchantId, $merchantKey);
        $currentKeys = $this->guard->merchantKeys($user, null, $current->merchant_key);

        $identityChanged = $account !== $current->account_id
            || $amount !== abs($current->amount)
            || $direction !== $current->direction
            || mb_strtolower(mb_trim($description)) !== mb_strtolower(mb_trim($current->description))
            || $current->transfer_to_account_id !== null
            || array_diff($keys, $currentKeys) !== []
            || array_diff($currentKeys, $keys) !== [];

        return $identityChanged
            && $this->guard->exists($user, $account, $amount, $direction, $merchantId, $description, $current->id, $merchantKey);
    }

    /** @param  array<string, mixed>  $attributes */
    private function changes(array $attributes, PlannedTransaction $current, string $column): bool
    {
        return array_key_exists($column, $attributes) && $attributes[$column] !== $current->{$column};
    }

    private function assertTrackedAccount(User $user, mixed $accountId): void
    {
        $this->refuse(Account::query()->where('user_id', $user->id)->tracked()->whereKey($accountId)->exists() ? null : PlanRefusal::UntrackedAccount);
    }

    private function assertVisibleCategory(User $user, mixed $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $this->refuse(Category::query()->where('user_id', $user->id)->visible()->whereKey($categoryId)->exists() ? null : PlanRefusal::HiddenCategory);
    }

    private function assertStartsAfterToday(User $user, mixed $startDate): void
    {
        $this->refuse(CarbonImmutable::parse($startDate)->startOfDay()->lessThan($this->earliestStartDate($user)) ? PlanRefusal::StartNotAfterToday : null);
    }

    private function lockUser(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    private function direction(mixed $direction): TransactionDirection
    {
        return $direction instanceof TransactionDirection ? $direction : TransactionDirection::from((string) $direction);
    }

    private function frequency(mixed $frequency): RecurrenceFrequency
    {
        return $frequency instanceof RecurrenceFrequency ? $frequency : RecurrenceFrequency::from((string) $frequency);
    }

    /**
     * @throws PlanWriteRefusedException
     */
    private function refuse(?PlanRefusal $refusal): void
    {
        if ($refusal !== null) {
            throw new PlanWriteRefusedException($refusal);
        }
    }
}
