<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionType;
use App\Enums\TransactionDirection;
use App\Models\Merchant;
use App\Models\MerchantAlias;
use App\Models\PlannedTransaction;
use App\Rules\PaymentUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * The recurring-series fields shared by the plan editors (the planned transaction manager
 * and the transaction modal's plan mode). The using component keeps its own untilType,
 * untilDate, notes and merchantId.
 */
trait EditsPlanSeries
{
    public string $planType = '';

    public string $paymentUrl = '';

    public bool $copyPaymentUrl = false;

    public bool $autopay = false;

    public string $endAfterCount = '';

    public string $renewalOn = '';

    public string $trialEndsOn = '';

    public string $cancelledOn = '';

    public string $reminderDays = '3';

    public string $graceDays = '3';

    public string $tolerancePercent = '7.5';

    public string $matchEarlyDays = '2';

    public string $matchLateDays = '7';

    public string $amountStrategy = 'fixed';

    /** @return array<string, mixed> */
    protected function seriesRules(string $untilType, PlannedTransactionType $type): array
    {
        return [
            'planType' => ['nullable', Rule::enum(PlannedTransactionType::class)],
            'paymentUrl' => ['nullable', 'string', 'max:'.PaymentUrl::MAX_LENGTH, new PaymentUrl],
            'copyPaymentUrl' => ['boolean'],
            'autopay' => ['boolean'],
            'endAfterCount' => $this->endsAfterCount($untilType, $type) ? ['required', 'integer', 'between:1,600'] : ['nullable'],
            'renewalOn' => ['nullable', 'date_format:Y-m-d'],
            'trialEndsOn' => ['nullable', 'date_format:Y-m-d'],
            'cancelledOn' => ['nullable', 'date_format:Y-m-d'],
            'reminderDays' => ['required', 'integer', 'between:0,365'],
            'graceDays' => ['required', 'integer', 'between:0,365'],
            'tolerancePercent' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'matchEarlyDays' => ['required', 'integer', 'between:0,90'],
            'matchLateDays' => ['required', 'integer', 'between:0,90'],
            'amountStrategy' => ['required', Rule::enum(PlanAmountStrategy::class)],
        ];
    }

    /**
     * An installment with a payment count always ends after that count, whatever end the form shows.
     */
    protected function endsAfterCount(string $untilType, PlannedTransactionType $type): bool
    {
        return $untilType === 'after-count'
            || ($type === PlannedTransactionType::Installment && mb_trim($this->endAfterCount) !== '');
    }

    /** The type the plan will be saved with, which may differ from the select once the direction or destination changed. */
    protected function resolvedSeriesType(TransactionDirection $direction, bool $isTransfer): PlannedTransactionType
    {
        return PlannedTransactionType::resolve(PlannedTransactionType::tryFrom($this->planType), $direction, $isTransfer);
    }

    /** @return array<string, mixed> */
    protected function seriesAttributes(
        TransactionDirection $direction,
        bool $isTransfer,
        ?int $merchantId,
        string $notes,
        string $untilType,
        ?string $untilDate,
    ): array {
        $type = $this->resolvedSeriesType($direction, $isTransfer);
        $counted = $this->endsAfterCount($untilType, $type);

        return [
            'type' => $type,
            'merchant_key' => $merchantId === null
                ? null
                : Merchant::query()->where('user_id', auth()->id())->whereKey($merchantId)->value('merchant_key'),
            'payment_url' => PaymentUrl::normalise($this->paymentUrl),
            'notes' => $notes !== '' ? $notes : null,
            'autopay' => $this->autopay,
            'until_date' => ! $counted && $untilType === 'until-date' && $untilDate !== '' ? $untilDate : null,
            'end_after_count' => $counted ? (int) $this->endAfterCount : null,
            'renewal_on' => $this->renewalOn !== '' ? $this->renewalOn : null,
            'trial_ends_on' => $this->trialEndsOn !== '' ? $this->trialEndsOn : null,
            'cancelled_on' => $this->cancelledOn !== '' ? $this->cancelledOn : null,
            'reminder_days' => (int) $this->reminderDays,
            'grace_days' => (int) $this->graceDays,
            'amount_tolerance_bps' => (int) round((float) $this->tolerancePercent * 100),
            'match_early_days' => (int) $this->matchEarlyDays,
            'match_late_days' => (int) $this->matchLateDays,
            'amount_strategy' => PlanAmountStrategy::from($this->amountStrategy),
        ];
    }

    protected function fillSeriesFrom(PlannedTransaction $plan): void
    {
        $this->planType = $plan->type->value;
        $this->paymentUrl = $plan->payment_url ?? '';
        $this->copyPaymentUrl = false;
        $this->autopay = $plan->autopay;
        $this->endAfterCount = $plan->end_after_count === null ? '' : (string) $plan->end_after_count;
        $this->renewalOn = $plan->renewal_on?->format('Y-m-d') ?? '';
        $this->trialEndsOn = $plan->trial_ends_on?->format('Y-m-d') ?? '';
        $this->cancelledOn = $plan->cancelled_on?->format('Y-m-d') ?? '';
        $this->reminderDays = (string) $plan->reminder_days;
        $this->graceDays = (string) $plan->grace_days;
        $this->tolerancePercent = (string) ($plan->amount_tolerance_bps / 100);
        $this->matchEarlyDays = (string) $plan->match_early_days;
        $this->matchLateDays = (string) $plan->match_late_days;
        $this->amountStrategy = $plan->amount_strategy->value;
    }

    protected function resetSeriesFields(): void
    {
        $this->planType = '';
        $this->paymentUrl = '';
        $this->copyPaymentUrl = false;
        $this->autopay = false;
        $this->endAfterCount = '';
        $this->renewalOn = '';
        $this->trialEndsOn = '';
        $this->cancelledOn = '';
        $this->reminderDays = '3';
        $this->graceDays = '3';
        $this->tolerancePercent = '7.5';
        $this->matchEarlyDays = '2';
        $this->matchLateDays = '7';
        $this->amountStrategy = 'fixed';
    }

    /**
     * The merchant picker value for a stored key: the merchant owning the key, or the one it was merged into.
     */
    protected function merchantIdForKey(int $userId, ?string $merchantKey): ?int
    {
        if ($merchantKey === null) {
            return null;
        }

        $id = Merchant::query()->where('user_id', $userId)->where('merchant_key', $merchantKey)->value('id')
            ?? MerchantAlias::query()->where('user_id', $userId)->where('merchant_key', $merchantKey)->value('merchant_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Gives the saved plan's payment link to the user's other plans with the same merchant or the
     * exact same description. Pay-cycle income stays out; call inside the saving transaction.
     */
    protected function copyPaymentUrlToMatching(PlannedTransaction $plan): void
    {
        if (! $this->copyPaymentUrl || $plan->payment_url === null) {
            return;
        }

        PlannedTransaction::query()
            ->where('user_id', $plan->user_id)
            ->whereKeyNot($plan->id)
            ->where(fn (Builder $query): Builder => $query->whereNull('is_pay_cycle_income')->orWhere('is_pay_cycle_income', false))
            ->where(fn (Builder $query): Builder => $query
                ->when($plan->description !== '', fn (Builder $q): Builder => $q->orWhere('description', $plan->description))
                ->when($plan->merchant_key !== null, fn (Builder $q): Builder => $q->orWhere('merchant_key', $plan->merchant_key))
                ->when($plan->description === '' && $plan->merchant_key === null, fn (Builder $q): Builder => $q->whereRaw('1 = 0')))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(fn (PlannedTransaction $other): bool => $other->update(['payment_url' => $plan->payment_url]));
    }

    /**
     * Options for the type select, and whether it is locked to transfer.
     *
     * @return array{options: list<PlannedTransactionType>, locked: bool}
     */
    protected function typeChoices(TransactionDirection $direction, bool $isTransfer): array
    {
        return [
            'options' => $isTransfer ? [PlannedTransactionType::Transfer] : PlannedTransactionType::selectableFor($direction),
            'locked' => $isTransfer,
        ];
    }
}
