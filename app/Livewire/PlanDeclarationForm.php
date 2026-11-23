<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Concerns\EditsPlanSeries;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\RecentCategoryPicks;
use App\Services\Recurring\PlanDuplicateGuard;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class PlanDeclarationForm extends Component
{
    use EditsPlanSeries;

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

    #[On('declare-plan')]
    public function open(string $kind): void
    {
        $user = $this->authenticatedUser();

        $this->resetValidation();
        $this->resetSeriesFields();
        $this->kind = $kind === self::KIND_INCOME ? self::KIND_INCOME : self::KIND_BILL;
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
        $this->showModal = true;
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
            'frequencies' => RecurrenceFrequency::selectable(),
        ]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'frequency' => ['required', 'string', Rule::in(array_map(static fn (RecurrenceFrequency $frequency): string => $frequency->value, RecurrenceFrequency::selectable()))],
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
