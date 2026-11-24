<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\PlanPattern;
use App\DTOs\PlanSmartFillSuggestion;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Exceptions\TypeSafe\TypeSafeException;
use App\Livewire\Concerns\EditsPlanSeries;
use App\Livewire\Concerns\PicksCadence;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecentCategoryPicks;
use App\Services\Recurring\PlanDuplicateGuard;
use App\Services\Recurring\PlanPatternPicker;
use App\Services\Recurring\PlanSmartFiller;
use App\Services\Recurring\PlanTransactionSearch;
use App\Support\Recurrence\NextDueDate;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class PlanDeclarationForm extends Component
{
    use EditsPlanSeries;
    use PicksCadence;
    use WithPagination;

    public const string KIND_BILL = 'bill';

    public const string KIND_INCOME = 'income';

    public bool $showModal = false;

    #[Locked]
    public string $kind = self::KIND_BILL;

    public string $description = '';

    public string $amount = '';

    public string $startDate = '';

    public string $frequency = '';

    public int|string|null $accountId = null;

    public int|string|null $categoryId = null;

    public int|string|null $merchantId = null;

    public string $notes = '';

    public string $searchTerm = '';

    public string $smartFillNotice = '';

    #[On('declare-plan')]
    public function open(string $kind): void
    {
        $user = $this->authenticatedUser();

        $this->resetValidation();
        $this->resetSeriesFields();
        $this->resetCadence();
        $this->kind = $kind === self::KIND_INCOME ? self::KIND_INCOME : self::KIND_BILL;
        $this->planType = $this->defaultPlanType();
        $this->description = '';
        $this->amount = '';
        $this->startDate = $user->today()->toDateString();
        $this->frequency = $this->kind === self::KIND_INCOME
            ? RecurrenceFrequency::Every2Weeks->value
            : RecurrenceFrequency::EveryMonth->value;
        $this->accountId = $this->defaultAccountId($user);
        $this->categoryId = null;
        $this->merchantId = null;
        $this->notes = '';
        $this->searchTerm = '';
        $this->smartFillNotice = '';
        $this->resetPage('searchPage');
        unset($this->patterns);
        $this->showModal = true;
    }

    public function updatedSearchTerm(): void
    {
        $this->resetPage('searchPage');
    }

    /**
     * The patterns the detector found for what is being declared, that the user has not declared.
     * Kept between requests so typing in the search does not run the detector again; open() clears it.
     *
     * @return Collection<int, PlanPattern>
     */
    #[Computed(persist: true)]
    public function patterns(): Collection
    {
        return app(PlanPatternPicker::class)->patterns($this->authenticatedUser(), $this->declaredDirection());
    }

    /**
     * The user's own transactions matching the search, a page at a time; nothing until something is typed.
     *
     * @return LengthAwarePaginator<int, Transaction>|null
     */
    #[Computed]
    public function searchResults(): ?LengthAwarePaginator
    {
        return mb_trim($this->searchTerm) === ''
            ? null
            : app(PlanTransactionSearch::class)->search($this->authenticatedUser(), $this->searchTerm);
    }

    /**
     * Proposes values from the user's past charges into the form without saving anything. A failed
     * or unsure call leaves the form as it was and shows a soft notice.
     */
    public function smartFill(): void
    {
        abort_unless(PlanSmartFiller::available(), 404);

        $user = $this->authenticatedUser();
        $this->resetValidation();
        $this->smartFillNotice = '';
        $description = mb_trim($this->description);

        if ($description === '') {
            $this->addError('smartFill', __('Type a description first, then use smart fill.'));

            return;
        }

        $filler = app(PlanSmartFiller::class);

        if (! $filler->allow($user)) {
            $this->addError('smartFill', __('You have used smart fill a lot this hour. Try again a little later.'));

            return;
        }

        try {
            $suggestion = $filler->suggest($user, $description, $this->declaredDirection(), $this->selectedCurrency($user));
        } catch (TypeSafeException $exception) {
            Log::warning('Plan smart fill failed', ['user_id' => $user->id, 'exception' => $exception::class]);
            $this->addError('smartFill', __('Smart fill could not run just now. Your form is unchanged.'));

            return;
        }

        if ($suggestion === null) {
            $this->smartFillNotice = __('No past charge matches that description, so there was nothing to fill from.');

            return;
        }

        if ($suggestion->isEmpty()) {
            $this->smartFillNotice = __('Smart fill was not sure about anything, so nothing changed.');

            return;
        }

        $this->applySuggestion($suggestion, $user);
        $this->smartFillNotice = __('Smart fill proposed these values. Review them: nothing is saved until you add the plan.');
    }

    public function usePattern(string $key): void
    {
        $pattern = $this->patterns->firstWhere('key', $key); // @phpstan-ignore property.notFound

        if ($pattern === null) {
            $this->addError('pattern', __('That pattern is no longer available.'));

            return;
        }

        $candidate = $pattern->candidate;
        $user = $this->authenticatedUser();

        $this->resetValidation();
        $this->prefill(
            $candidate->direction,
            $candidate->cleanDescription,
            abs($candidate->amount),
            $candidate->accountId,
            $candidate->categoryId,
            null,
            $candidate->frequency,
            NextDueDate::after($candidate->frequency, $pattern->latestDate, $user->today()),
        );
    }

    public function startFromTransaction(int $id): void
    {
        $user = $this->authenticatedUser();
        $transaction = app(PlanTransactionSearch::class)->find($user, $id);

        abort_if($transaction === null, 404);

        $frequency = $transaction->direction === TransactionDirection::Credit
            ? RecurrenceFrequency::Every2Weeks
            : RecurrenceFrequency::EveryMonth;

        $this->resetValidation();
        $this->prefill(
            $transaction->direction,
            $transaction->merchant_name ?: ($transaction->clean_description ?: (string) $transaction->description),
            abs($transaction->amount),
            $transaction->account_id,
            $transaction->category_id,
            $transaction->merchant_id,
            $frequency,
            NextDueDate::after($frequency, $transaction->post_date->toImmutable(), $user->today()),
        );
    }

    public function save(): void
    {
        $user = $this->authenticatedUser();
        $this->description = mb_trim($this->description);

        $validated = $this->validate($this->rules(), $this->messages());

        $direction = $this->declaredDirection();
        $cents = (int) round((float) $validated['amount'] * 100);
        $accountId = (int) $validated['accountId'];
        $merchantId = $this->resolvedId($this->merchantId);
        $categoryId = $this->resolvedId($this->categoryId);
        $description = mb_trim($validated['description']);

        $created = DB::transaction(function () use ($user, $direction, $cents, $accountId, $merchantId, $categoryId, $description, $validated): ?PlannedTransaction {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (app(PlanDuplicateGuard::class)->exists($user, $accountId, $cents, $direction, $merchantId, $description)) {
                return null;
            }

            return PlannedTransaction::query()->create([
                'user_id' => $user->id,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'amount' => $cents,
                'direction' => $direction,
                'description' => $description,
                'start_date' => $validated['startDate'],
                'frequency' => RecurrenceFrequency::from($validated['frequency']),
                'schedule_pinned' => true,
                ...$this->cadenceAttributes(RecurrenceFrequency::from($validated['frequency'])),
                'origin' => PlanOrigin::Declared,
                'is_active' => true,
                ...$this->seriesAttributes($direction, false, $merchantId, $this->notes, 'always', null),
            ]);
        });

        if ($created === null) {
            $this->addError('description', $this->kind === self::KIND_INCOME
                ? __('This income already exists on that account.')
                : __('This bill already exists on that account.'));

            return;
        }

        if ($this->categoryId !== null && $this->categoryId !== '') {
            app(RecentCategoryPicks::class)->record($user, (int) $this->categoryId);
        }

        Flux::toast(text: $this->kind === self::KIND_INCOME ? __('Income added') : __('Bill added'), variant: 'success');

        $this->dispatch('plan-declared', accountId: $created->account_id);
        $this->showModal = false;
    }

    public function render(): View
    {
        $user = $this->authenticatedUser();

        return view('livewire.plan-declaration-form', [
            'accounts' => Account::query()->where('user_id', $user->id)->active()->tracked()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::visibleSortedByFullPath($user->id),
            'merchants' => Merchant::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'frequencies' => RecurrenceFrequency::pickable(),
            'smartFillAvailable' => PlanSmartFiller::available(),
            'typeOptions' => $this->typeChoices($this->declaredDirection(), false)['options'],
            'patterns' => $this->showModal ? $this->patterns : collect(), // @phpstan-ignore property.notFound
            'results' => $this->showModal ? $this->searchResults : null, // @phpstan-ignore property.notFound
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'frequency' => ['required', 'string', Rule::in(array_map(static fn (RecurrenceFrequency $frequency): string => $frequency->value, RecurrenceFrequency::pickable()))],
            ...$this->cadenceRules(),
            'accountId' => ['required', Rule::exists('accounts', 'id')->where('user_id', auth()->id())->where('is_tracked', true)],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('user_id', auth()->id())->where('is_hidden', 0)],
            'merchantId' => ['nullable', Rule::exists('merchants', 'id')->where('user_id', auth()->id())],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->seriesRules('always', $this->resolvedSeriesType($this->declaredDirection(), false)),
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'accountId.required' => __('Choose the account this is paid from.'),
            'accountId.exists' => __('Choose one of your own accounts.'),
        ];
    }

    private function prefill(
        TransactionDirection $direction,
        string $description,
        int $cents,
        int $accountId,
        ?int $categoryId,
        ?int $merchantId,
        RecurrenceFrequency $frequency,
        CarbonImmutable $startDate,
    ): void {
        $user = $this->authenticatedUser();
        $kind = $direction === TransactionDirection::Credit ? self::KIND_INCOME : self::KIND_BILL;
        $this->smartFillNotice = '';

        if ($kind !== $this->kind) {
            $this->kind = $kind;
            $this->resetSeriesFields();
            $this->planType = $this->defaultPlanType();
            unset($this->patterns);
        }

        $this->description = mb_substr(mb_trim($description), 0, 255);
        $this->amount = number_format($cents / 100, 2, '.', '');
        $this->accountId = Account::query()->where('user_id', $user->id)->active()->tracked()->whereKey($accountId)->exists() ? $accountId : $this->defaultAccountId($user);
        $this->categoryId = $categoryId !== null && Category::query()->where('user_id', $user->id)->visible()->whereKey($categoryId)->exists() ? $categoryId : null;
        $this->merchantId = $merchantId !== null && Merchant::query()->where('user_id', $user->id)->whereKey($merchantId)->exists() ? $merchantId : null;
        $this->frequency = $frequency->isPickable()
            ? $frequency->value
            : ($kind === self::KIND_INCOME ? RecurrenceFrequency::Every2Weeks->value : RecurrenceFrequency::EveryMonth->value);
        $this->startDate = $startDate->toDateString();
    }

    private function selectedCurrency(User $user): ?string
    {
        $currency = Account::query()->where('user_id', $user->id)->whereKey($this->resolvedId($this->accountId))->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : null;
    }

    private function applySuggestion(PlanSmartFillSuggestion $suggestion, User $user): void
    {
        $direction = $this->declaredDirection();

        if ($suggestion->description !== null) {
            $this->description = mb_substr(mb_trim($suggestion->description), 0, 255);
        }

        if ($suggestion->amount !== null) {
            $this->amount = number_format($suggestion->amount / 100, 2, '.', '');
        }

        $frequency = $suggestion->frequency === null ? null : RecurrenceFrequency::tryFrom($suggestion->frequency);

        if ($frequency !== null && $frequency->isPickable()) {
            $this->frequency = $frequency->value;
        }

        if ($suggestion->startDate !== null) {
            $this->startDate = $suggestion->startDate;
        }

        if ($suggestion->categoryId !== null && Category::query()->where('user_id', $user->id)->visible()->whereKey($suggestion->categoryId)->exists()) {
            $this->categoryId = $suggestion->categoryId;
        }

        $type = $suggestion->type === null ? null : PlannedTransactionType::tryFrom($suggestion->type);

        if ($type !== null && in_array($type, PlannedTransactionType::selectableFor($direction), true)) {
            $this->planType = $type->value;
        }

        if ($suggestion->autopay !== null && $direction === TransactionDirection::Debit) {
            $this->autopay = $suggestion->autopay;
        }
    }

    private function defaultPlanType(): string
    {
        return ($this->kind === self::KIND_INCOME ? PlannedTransactionType::Income : PlannedTransactionType::Bill)->value;
    }

    private function declaredDirection(): TransactionDirection
    {
        return $this->kind === self::KIND_INCOME ? TransactionDirection::Credit : TransactionDirection::Debit;
    }

    private function defaultAccountId(User $user): ?int
    {
        $usable = Account::query()->where('user_id', $user->id)->active()->tracked();
        $primary = $user->primary_account_id === null ? null : (clone $usable)->whereKey($user->primary_account_id)->value('id');
        $id = $primary ?? (clone $usable)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function resolvedId(int|string|null $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
