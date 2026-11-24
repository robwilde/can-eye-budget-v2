<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\DTOs\PlanSmartFillSuggestion;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionType;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Livewire\Attributes\NotAudited;
use App\Livewire\Concerns\EditsPlanSeries;
use App\Livewire\Concerns\PicksCadence;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecentCategoryPicks;
use App\Services\Recurring\OccurrenceActions;
use App\Services\Recurring\OccurrenceSchedule;
use App\Services\Recurring\OccurrenceStateResolver;
use App\Services\Recurring\PlannedOccurrenceGenerator;
use App\Services\Recurring\PlanSmartFiller;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class PlannedTransactionManager extends Component
{
    use EditsPlanSeries;
    use PicksCadence;

    public ?int $accountId = null;

    public bool $showEditModal = false;

    public ?int $editingId = null;

    public string $amount = '';

    public string $frequency = '';

    public int|string|null $categoryId = null;

    #[Locked]
    public bool $categoryPicked = false;

    public string $direction = '';

    public bool $isActive = true;

    public ?string $untilDate = null;

    public int|string|null $merchantId = null;

    public string $notes = '';

    public ?int $occurrencesPlanId = null;

    /** @var array<int, string> */
    public array $snoozeDates = [];

    /** @var array<int, string> */
    public array $occurrenceAmounts = [];

    public ?int $smartConfigurePlanId = null;

    public string $smartConfigureNotice = '';

    /** @var list<array{field: string, current: string, suggested: string}> */
    public array $smartConfigureChanges = [];

    public bool $showDeleteModal = false;

    public ?int $deletingId = null;

    public string $deletingDescription = '';

    public function updatedCategoryId(): void
    {
        $this->categoryPicked = true;
    }

    public function mount(): void
    {
        $user = $this->authenticatedUser();
        $usable = $user->accounts()->active()->tracked();
        $primaryId = $user->primary_account_id === null
            ? null
            : (clone $usable)->whereKey($user->primary_account_id)->value('id');
        $firstAccountId = $primaryId ?? (clone $usable)->orderBy('id')->value('id');

        $this->accountId = $firstAccountId === null ? null : (int) $firstAccountId;
    }

    #[On('plan-declared')]
    public function showDeclaredPlan(int $accountId): void
    {
        $this->accountId = $accountId;
    }

    /**
     * Lists only the fields smart fill would set differently from the plan today. Nothing is changed.
     */
    public function smartConfigure(int $id): void
    {
        abort_unless(PlanSmartFiller::available(), 404);

        $plan = $this->findUserPlan($id);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        $user = $this->authenticatedUser();
        $this->resetValidation();
        $this->smartConfigurePlanId = $plan->id;
        $this->smartConfigureNotice = '';
        $this->smartConfigureChanges = [];
        $filler = app(PlanSmartFiller::class);

        if (! $filler->allow($user)) {
            $this->addError('smartConfigure', __('You have used smart configure a lot this hour. Try again a little later.'));

            return;
        }

        try {
            $suggestion = $filler->suggest($user, $plan->description, $plan->direction, $plan->account->currency, withName: false);
        } catch (TypeSafeException $exception) {
            Log::warning('Plan smart configure failed', ['user_id' => $user->id, 'plan_id' => $plan->id, 'exception' => $exception::class]);
            $this->addError('smartConfigure', __('Smart configure could not run just now. Your plan is unchanged.'));

            return;
        }

        if ($suggestion === null) {
            $this->smartConfigureNotice = __('No past charge matches this plan, so there was nothing to compare with.');

            return;
        }

        $this->smartConfigureChanges = $this->contradictions($plan, $suggestion);

        if ($this->smartConfigureChanges === []) {
            $this->smartConfigureNotice = __('Nothing in your past charges contradicts this plan.');
        }
    }

    public function openEdit(int $id): void
    {
        $plan = $this->findUserPlan($id);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        $this->resetValidation();
        $this->editingId = $plan->id;
        $this->amount = (string) ($plan->amount / 100);
        $this->frequency = $plan->frequency->value;
        $this->categoryId = $plan->category_id;
        $this->categoryPicked = false;
        $this->direction = $plan->direction->value;
        $this->isActive = $plan->is_active;
        $this->untilDate = $plan->until_date?->format('Y-m-d');
        $this->merchantId = $this->merchantIdForKey($plan->user_id, $plan->merchant_key);
        $this->notes = $plan->notes ?? '';
        $this->fillSeriesFrom($plan);
        $this->fillCadenceFrom($plan);
        $this->showEditModal = true;
    }

    public function save(): void
    {
        $this->validate($this->formRules());

        if ($this->editingId === null) {
            return;
        }

        $plan = $this->findUserPlan($this->editingId);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        $categoryId = $this->resolvedCategoryId();

        $direction = TransactionDirection::from($this->direction);
        $merchantId = $this->resolvedMerchantId();

        DB::transaction(function () use ($plan, $categoryId, $direction, $merchantId): void {
            $plan->update([
                'amount' => (int) round((float) $this->amount * 100),
                'frequency' => $this->frequency,
                ...$this->cadenceAttributes(RecurrenceFrequency::from($this->frequency), $plan),
                ...($this->cadenceChanged($plan, RecurrenceFrequency::from($this->frequency)) ? ['schedule_pinned' => true] : []),
                'category_id' => $categoryId,
                'direction' => $direction,
                'is_active' => $this->isActive,
                ...$this->seriesAttributes(
                    $direction,
                    $plan->transfer_to_account_id !== null,
                    $merchantId,
                    $this->notes,
                    $this->effectiveUntilType(),
                    $this->untilDate,
                ),
            ]);

            $this->copyPaymentUrlToMatching($plan);
        });

        if ($this->categoryPicked && $categoryId !== null) {
            app(RecentCategoryPicks::class)->record($this->authenticatedUser(), $categoryId);
        }

        if ($plan->id === $this->smartConfigurePlanId) {
            $this->smartConfigurePlanId = null;
            $this->smartConfigureChanges = [];
            $this->smartConfigureNotice = '';
        }

        Flux::toast(text: 'Planned transaction updated', variant: 'success');

        $this->showEditModal = false;
        $this->resetEditForm();
    }

    #[NotAudited]
    #[On('occurrence-payment-changed')]
    public function refreshAfterPayment(): void {}

    public function skipOccurrence(int $occurrenceId): void
    {
        $this->changeOccurrence(fn (User $user) => app(OccurrenceActions::class)->skip($user, $occurrenceId));
    }

    public function markOccurrenceMissed(int $occurrenceId): void
    {
        $this->changeOccurrence(fn (User $user) => app(OccurrenceActions::class)->markMissed($user, $occurrenceId));
    }

    public function reopenOccurrence(int $occurrenceId): void
    {
        $this->changeOccurrence(fn (User $user) => app(OccurrenceActions::class)->reopen($user, $occurrenceId));
    }

    public function snoozeOccurrence(int $occurrenceId): void
    {
        $date = $this->snoozeDates[$occurrenceId] ?? '';

        $this->changeOccurrence(fn (User $user) => app(OccurrenceActions::class)->snooze($user, $occurrenceId, $date));

        unset($this->snoozeDates[$occurrenceId]);
    }

    public function setOccurrenceAmount(int $occurrenceId): void
    {
        $amount = mb_trim($this->occurrenceAmounts[$occurrenceId] ?? '');

        if ($amount !== '' && preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
            $this->addError('occurrence', 'Use a positive amount with at most 2 decimal places.');

            return;
        }

        $cents = $amount === '' ? null : (int) round((float) $amount * 100);

        $this->changeOccurrence(fn (User $user) => app(OccurrenceActions::class)->setExpectedAmount($user, $occurrenceId, $cents));

        unset($this->occurrenceAmounts[$occurrenceId]);
    }

    public function toggleActive(int $id): void
    {
        $plan = $this->findUserPlan($id);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        DB::transaction(fn (): bool => $plan->update(['is_active' => ! $plan->is_active]));
    }

    public function confirmDelete(int $id): void
    {
        $plan = $this->findUserPlan($id);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        $this->deletingId = $plan->id;
        $this->deletingDescription = $plan->description;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }

        $plan = $this->findUserPlan($this->deletingId);

        if (! $plan || ! $this->guardEditablePlan($plan)) {
            return;
        }

        DB::transaction(fn (): ?bool => $plan->delete());

        Flux::toast(text: 'Planned transaction deleted', variant: 'success');

        $this->showDeleteModal = false;
        $this->resetDeleteForm();
    }

    public function render(): View
    {
        $user = $this->authenticatedUser();
        $direction = TransactionDirection::tryFrom($this->direction) ?? TransactionDirection::Debit;
        $editing = $this->editingId === null ? null : $this->findUserPlan($this->editingId);

        return view('livewire.planned-transaction-manager', [
            'typeChoices' => $this->typeChoices($direction, $editing?->transfer_to_account_id !== null),
            'amountStrategies' => PlanAmountStrategy::cases(),
            'smartFillAvailable' => PlanSmartFiller::available(),
            'merchants' => Merchant::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'accounts' => $this->accountsForUser($user),
            'plans' => $this->plannedTransactionsForSelectedAccount($user),
            'categories' => Category::visibleSortedByFullPath($user->id),
            'frequencies' => $this->frequencyOptions($editing),
            'directions' => TransactionDirection::cases(),
            'occurrences' => $this->occurrencesOfSelectedPlan($user),
            'occurrenceStates' => new OccurrenceStateResolver,
            'today' => $user->today(),
        ]);
    }

    /**
     * @return list<array{field: string, current: string, suggested: string}>
     */
    private function contradictions(PlannedTransaction $plan, PlanSmartFillSuggestion $suggestion): array
    {
        $changes = [];
        $add = static function (string $field, string $current, string $suggested) use (&$changes): void {
            $changes[] = ['field' => $field, 'current' => $current, 'suggested' => $suggested];
        };

        if ($suggestion->amount !== null && $suggestion->amount !== abs($plan->amount)) {
            $add(__('Amount'), MoneyCast::format(abs($plan->amount)), MoneyCast::format($suggestion->amount));
        }

        $frequency = $suggestion->frequency === null ? null : RecurrenceFrequency::tryFrom($suggestion->frequency);

        if ($frequency !== null && $frequency !== $plan->frequency) {
            $add(__('Repeats'), $plan->frequency->label($plan->recurrence_interval), $frequency->label());
        }

        $type = $suggestion->type === null ? null : PlannedTransactionType::tryFrom($suggestion->type);

        if ($type !== null && $type !== $plan->type && $plan->transfer_to_account_id === null) {
            $add(__('Type'), $plan->type->label(), $type->label());
        }

        if ($suggestion->categoryId !== null && $plan->category_id !== null && $suggestion->categoryId !== $plan->category_id) {
            $categories = Category::allWithLinkedParents($plan->user_id)->keyBy('id');

            if ($categories->has($suggestion->categoryId) && $categories->has($plan->category_id)) {
                $add(__('Category'), $categories[$plan->category_id]->fullPath(), $categories[$suggestion->categoryId]->fullPath());
            }
        }

        if ($suggestion->autopay !== null && $plan->direction === TransactionDirection::Debit && $suggestion->autopay !== $plan->autopay) {
            $add(__('Autopay'), $plan->autopay ? __('On') : __('Off'), $suggestion->autopay ? __('On') : __('Off'));
        }

        $monthly = [RecurrenceFrequency::EveryMonth, RecurrenceFrequency::Every3Months, RecurrenceFrequency::Every6Months, RecurrenceFrequency::EveryYear];

        if ($suggestion->startDate !== null && $frequency === $plan->frequency && in_array($plan->frequency, $monthly, true)) {
            $suggested = CarbonImmutable::parse($suggestion->startDate);
            $current = $this->scheduledOnOrAfter($plan, $suggested->startOfMonth());

            if ($current !== null && ! $current->isSameDay($suggested)) {
                $add(__('Next due'), $current->toDateString(), $suggested->toDateString());
            }
        }

        return $changes;
    }

    /** The plan's own scheduled date on or after the given day, before any weekend adjustment. */
    private function scheduledOnOrAfter(PlannedTransaction $plan, CarbonImmutable $from): ?CarbonImmutable
    {
        $read = 0;

        foreach ($plan->schedule($from) as $scheduled) {
            if ($scheduled->original->greaterThanOrEqualTo($from)) {
                return $scheduled->original;
            }

            if (++$read >= 400) {
                break;
            }
        }

        return null;
    }

    private function changeOccurrence(Closure $change): void
    {
        $this->resetErrorBag('occurrence');

        try {
            $change($this->authenticatedUser());
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    /**
     * The stored occurrences of the plan whose list is open, from the current cycle on.
     *
     * @return EloquentCollection<int, PlannedOccurrence>
     */
    private function occurrencesOfSelectedPlan(User $user): EloquentCollection
    {
        if ($this->occurrencesPlanId === null) {
            return new EloquentCollection;
        }

        $from = app(PlannedOccurrenceGenerator::class)->cycleStart($user, $user->today())->toDateString();

        return PlannedOccurrence::query()
            ->forUser($user)
            ->withSum('payments', 'amount')
            ->where('planned_transaction_id', $this->occurrencesPlanId)
            ->where(static fn (Builder $query) => $query->where('due_date', '>=', $from)->orWhere('snoozed_until', '>=', $from))
            ->with('plannedTransaction')
            ->orderBy('original_date')
            ->limit(24)
            ->get();
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function findUserPlan(int $id): ?PlannedTransaction
    {
        $plan = PlannedTransaction::query()
            ->where('user_id', auth()->id())
            ->find($id);

        return $plan instanceof PlannedTransaction ? $plan : null;
    }

    private function guardEditablePlan(PlannedTransaction $plan): bool
    {
        if (! $plan->is_pay_cycle_income) {
            return true;
        }

        $this->resetModals();
        Flux::toast(text: 'Pay cycle income is managed elsewhere', variant: 'warning');

        return false;
    }

    /** @return Collection<int, Account> */
    private function accountsForUser(User $user): Collection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->where(fn ($query) => $query->active()->orWhere('id', $this->accountId))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, PlannedTransaction> */
    private function plannedTransactionsForSelectedAccount(User $user): Collection
    {
        if (! $this->selectedAccountBelongsTo($user)) {
            return collect();
        }

        $today = $user->today();

        $plans = PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $this->accountId)
            ->with(['category.parent.parent'])
            ->withCount([
                'transactions as matched_count' => static function (Builder $query): Builder {
                    /** @var Builder<Transaction> $query */
                    return $query->current();
                },
            ])
            ->orderByDesc('is_active')
            ->orderBy('description')
            ->get();

        $nextByPlan = (new OccurrenceSchedule)->nextFor($plans->collect(), $today);

        $plans->each(function (PlannedTransaction $plan) use ($nextByPlan): void {
            $plan->setAttribute('next_occurrence', $nextByPlan[$plan->id]?->date);
        });

        return $plans;
    }

    private function selectedAccountBelongsTo(User $user): bool
    {
        if ($this->accountId === null) {
            return false;
        }

        return Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->whereKey($this->accountId)
            ->exists();
    }

    /** @return array<string, list<mixed>> */
    private function formRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'frequency' => ['required', 'string', Rule::in($this->frequencyValues())],
            ...$this->cadenceRules(),
            'direction' => ['required', 'string', Rule::in($this->directionValues())],
            'untilDate' => ['nullable', 'date', $this->singleEndRule()],
            'merchantId' => ['nullable', Rule::exists('merchants', 'id')->where('user_id', auth()->id())],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->seriesRules($this->effectiveUntilType(), $this->editedType()),
        ];
    }

    /**
     * The cadences to offer, plus the edited plan's own as Custom when the picker cannot express it.
     *
     * @return list<RecurrenceFrequency>
     */
    private function frequencyOptions(?PlannedTransaction $editing): array
    {
        return $this->cadenceOptions($editing?->frequency);
    }

    private function editedType(): PlannedTransactionType
    {
        $plan = $this->editingId === null ? null : $this->findUserPlan($this->editingId);

        return $this->resolvedSeriesType(
            TransactionDirection::tryFrom($this->direction) ?? TransactionDirection::Debit,
            $plan?->transfer_to_account_id !== null,
        );
    }

    /** A payment count ends the plan after that many payments; otherwise an end date does, or nothing. */
    private function effectiveUntilType(): string
    {
        return match (true) {
            mb_trim($this->endAfterCount) !== '' => 'after-count',
            filled($this->untilDate) => 'until-date',
            default => 'always',
        };
    }

    /** An installment's payment count replaces its end date; any other plan must pick one. */
    private function singleEndRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (mb_trim($this->endAfterCount) !== '' && $this->editedType() !== PlannedTransactionType::Installment) {
                $fail('Choose an end date or a payment count, not both.');
            }
        };
    }

    private function resolvedMerchantId(): ?int
    {
        return $this->merchantId === null || $this->merchantId === '' ? null : (int) $this->merchantId;
    }

    private function resolvedCategoryId(): ?int
    {
        if ($this->categoryId === null || $this->categoryId === '') {
            return null;
        }

        $categoryId = (int) $this->categoryId;

        return Category::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->visible()
            ->whereKey($categoryId)
            ->exists()
                ? $categoryId
                : null;
    }

    /** @return list<string> */
    private function frequencyValues(): array
    {
        return array_map(
            static fn (RecurrenceFrequency $frequency): string => $frequency->value,
            RecurrenceFrequency::cases(),
        );
    }

    /** @return list<string> */
    private function directionValues(): array
    {
        return array_map(
            static fn (TransactionDirection $direction): string => $direction->value,
            TransactionDirection::cases(),
        );
    }

    private function resetEditForm(): void
    {
        $this->editingId = null;
        $this->amount = '';
        $this->frequency = '';
        $this->categoryId = null;
        $this->categoryPicked = false;
        $this->direction = '';
        $this->isActive = true;
        $this->untilDate = null;
        $this->merchantId = null;
        $this->notes = '';
        $this->resetSeriesFields();
        $this->resetCadence();
    }

    private function resetDeleteForm(): void
    {
        $this->deletingId = null;
        $this->deletingDescription = '';
    }

    private function resetModals(): void
    {
        $this->showEditModal = false;
        $this->showDeleteModal = false;
        $this->resetEditForm();
        $this->resetDeleteForm();
    }
}
