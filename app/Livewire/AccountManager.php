<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Enums\LoanRateType;
use App\Enums\ValuationKind;
use App\Exceptions\InvalidValuationException;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\User;
use App\Services\AccountValuationService;
use App\Services\NetWorthService;
use App\Services\Projection\AccountBalanceHistoryBuilder;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\UntrackedAccountCreator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class AccountManager extends Component
{
    public bool $showFormModal = false;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $editingAccountId = null;

    public ?int $deletingAccountId = null;

    public string $deletingAccountName = '';

    public int $deletingTransactionCount = 0;

    public string $name = '';

    public string $balance = '';

    public bool $hasCreditLimit = false;

    public string $credit_limit = '';

    public string $description = '';

    public bool $excludeFromReports = false;

    public string $type = '';

    public string $group = '';

    public string $institution = '';

    public string $currency = '';

    /** The form is creating/editing an untracked (manual-balance, long-term) account. Server-set only. */
    #[Locked]
    public bool $isUntracked = false;

    /** The edited account's balance comes from a provider, so the form must not offer to change it. Server-set only. */
    #[Locked]
    public bool $balanceReadOnly = false;

    public bool $showReconcileModal = false;

    public ?int $reconcilingAccountId = null;

    public string $reconcilingAccountName = '';

    public string $reconcileBalance = '';

    public string $reconcileDate = '';

    #[Locked]
    public ?int $editingValuationId = null;

    /** @var array{before: int, after: int, date: string}|null */
    public ?array $reconcilePreview = null;

    public string $openingBalance = '';

    public string $openingBalanceDate = '';

    #[Locked]
    public ?int $openedBalance = null;

    #[Locked]
    public ?int $openedOpeningBalance = null;

    #[Locked]
    public ?string $openedOpeningBalanceDate = null;

    public string $rateType = '';

    public string $rate = '';

    public string $termMonths = '';

    public string $startDate = '';

    public string $originalPrincipal = '';

    public string $downPayment = '';

    public string $insurance = '';

    public string $minimumPayment = '';

    public string $annualFee = '';

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->group = AccountGroup::DayToDay->value;
        $this->type = AccountClass::Transaction->value;
        $this->currency = $this->authenticatedUser()->base_currency;
        $this->showFormModal = true;
    }

    public function openAddUntrackedModal(): void
    {
        $this->resetForm();
        $this->isUntracked = true;
        $this->group = AccountGroup::LongTermSavings->value;
        $this->type = AccountClass::Savings->value;
        $this->currency = $this->authenticatedUser()->base_currency;
        $this->showFormModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if (! $account) {
            return;
        }

        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->balance = number_format($account->balance / 100, 2, '.', '');
        $this->openedBalance = $account->balance;
        $this->hasCreditLimit = $account->credit_limit !== null;
        $this->credit_limit = $account->credit_limit !== null
            ? number_format($account->credit_limit / 100, 2, '.', '')
            : '';
        $this->description = $account->description ?? '';
        $this->excludeFromReports = $account->exclude_from_reports;
        $this->type = $account->type->value;
        $this->group = $account->group->value;
        $this->institution = $account->institution ?? '';
        $this->currency = $account->currency;

        $opening = $account->valuations()->where('kind', ValuationKind::OpeningBalance)->first();
        $this->openingBalance = $opening !== null ? number_format($opening->balance / 100, 2, '.', '') : '';
        $this->openingBalanceDate = $opening?->valued_on->toDateString() ?? '';
        $this->openedOpeningBalance = $opening?->balance;
        $this->openedOpeningBalanceDate = $opening?->valued_on->toDateString();
        $this->isUntracked = ! $account->is_tracked;
        $this->balanceReadOnly = ! $account->isManuallyBalanced();

        $detail = $account->detail;
        $this->rateType = $detail->rate_type->value ?? '';
        $this->rate = $detail->rate ?? '';
        $this->termMonths = $detail?->term_months !== null ? (string) $detail->term_months : '';
        $this->startDate = $detail?->start_date?->toDateString() ?? '';
        $this->originalPrincipal = $this->centsToInput($detail?->original_principal);
        $this->downPayment = $this->centsToInput($detail?->down_payment);
        $this->insurance = $this->centsToInput($detail?->insurance);
        $this->minimumPayment = $this->centsToInput($detail?->minimum_payment);
        $this->annualFee = $this->centsToInput($detail?->annual_fee);
        $this->showFormModal = true;
    }

    public function save(): void
    {
        // When editing, the persisted account decides the mode, never the form state.
        $untracked = $this->editingAccountId
            ? $this->findUserAccount($this->editingAccountId)?->is_tracked === false
            : $this->isUntracked;

        if ($untracked) {
            $this->saveUntracked();

            return;
        }

        $account = $this->editingAccountId ? $this->findUserAccount($this->editingAccountId) : null;

        if ($this->editingAccountId && $account === null) {
            return;
        }

        $providerFed = $account !== null && ! $account->isManuallyBalanced();
        $rules = $this->formRules();

        if ($providerFed) {
            unset($rules['balance']);
        }

        $validated = $this->validate($rules);

        $details = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'group' => $validated['group'],
            'institution' => $validated['institution'] ?: null,
            'description' => $validated['description'] ?: null,
            'exclude_from_reports' => $validated['excludeFromReports'],
        ];

        if ($this->hasCreditLimit && $validated['credit_limit'] !== null && $validated['credit_limit'] !== '') {
            $details['credit_limit'] = $this->reconcileCents($validated['credit_limit']);
        } else {
            $details['credit_limit'] = null;
        }

        $cents = $providerFed ? 0 : $this->reconcileCents($validated['balance']);
        $type = AccountClass::from($validated['type']);
        $newLoan = $account === null && $type->amortises();
        $principal = 0;
        $start = null;

        if ($newLoan) {
            $cents = -abs($cents);
            $principal = $this->reconcileCents($validated['originalPrincipal']);
            $start = CarbonImmutable::parse($validated['startDate'])->startOfDay();

            if ($start->isToday() && $cents !== -$principal) {
                $this->addError('balance', __('A loan that starts today owes its original principal.'));

                return;
            }
        }

        $failedField = $newLoan ? 'startDate' : 'openingBalanceDate';

        try {
            DB::transaction(function () use ($account, $details, $cents, $providerFed, $validated, $type, $newLoan, $principal, $start, &$failedField): void {
                $balanceChanged = false;

                if ($account === null) {
                    $account = Account::query()->create($details + $this->manualBalanceAttributes($cents) + [
                        'user_id' => auth()->id(),
                        'currency' => $validated['currency'],
                        'status' => AccountStatus::Active,
                    ]);

                    app(AccountBalanceHistoryBuilder::class)->rebuild($account);
                } else {
                    $balanceChanged = ! $providerFed && $cents !== $this->openedBalance;
                    $account->update($details);
                }

                $this->saveAccountDetail($account, $type, $validated);
                $valuations = app(AccountValuationService::class);

                if ($newLoan) {
                    $valuations->setOpeningBalance($account, -$principal, $start);

                    if ($start->lessThan(CarbonImmutable::today())) {
                        $failedField = 'balance';
                        $valuations->setCurrentBalance($account, $cents);
                    }

                    return;
                }

                $openingAmountChanged = $this->saveOpeningBalance($account);

                if ($balanceChanged && ! ($openingAmountChanged && $valuations->shiftsOpeningBalance($account))) {
                    $failedField = 'balance';
                    $valuations->setCurrentBalance($account, $cents);
                }
            });
        } catch (InvalidValuationException $exception) {
            $this->addError($failedField, $exception->getMessage());

            return;
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function openReconcileModal(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || ! $account->isManuallyBalanced()) {
            return;
        }

        $this->resetValidation();
        $this->editingValuationId = null;
        $this->reconcilePreview = null;
        $this->reconcilingAccountId = $account->id;
        $this->reconcilingAccountName = $account->name;
        $this->reconcileBalance = number_format($account->balance / 100, 2, '.', '');
        $this->reconcileDate = CarbonImmutable::today()->toDateString();
        $this->showReconcileModal = true;
    }

    /** First step: validates the entry and shows the balance before and after, writing nothing. */
    public function reconcile(): void
    {
        $validated = $this->validateReconcileForm();
        $account = $this->reconcilingAccount();

        if ($account === null) {
            return;
        }

        $date = CarbonImmutable::parse($validated['reconcileDate']);

        $this->reconcilePreview = [
            'before' => app(AccountValuationService::class)->balanceOn($account, $date, inclusive: false, excluding: $this->editingValuation()),
            'after' => $this->reconcileCents($validated['reconcileBalance']),
            'date' => $date->toDateString(),
        ];
    }

    public function confirmReconcile(): void
    {
        $validated = $this->validateReconcileForm();
        $account = $this->reconcilingAccount();

        if ($account === null) {
            return;
        }

        $service = app(AccountValuationService::class);
        $cents = $this->reconcileCents($validated['reconcileBalance']);
        $date = CarbonImmutable::parse($validated['reconcileDate']);
        $editing = $this->editingValuation();

        try {
            if ($this->editingValuationId !== null && $editing === null) {
                return;
            }

            $editing !== null
                ? $service->update($editing, $cents, $date)
                : $service->reconcile($account, $cents, $date);
        } catch (InvalidValuationException $exception) {
            $this->addError('reconcileDate', $exception->getMessage());
            $this->reconcilePreview = null;

            return;
        }

        $this->closeReconcileModal();
    }

    public function editReconciliation(int $valuationId): void
    {
        $valuation = $this->findReconciliation($valuationId);

        if ($valuation === null) {
            return;
        }

        $this->resetValidation();
        $this->reconcilePreview = null;
        $this->editingValuationId = $valuation->id;
        $this->reconcileBalance = number_format($valuation->balance / 100, 2, '.', '');
        $this->reconcileDate = $valuation->valued_on->toDateString();
    }

    public function deleteReconciliation(int $valuationId): void
    {
        $valuation = $this->findReconciliation($valuationId);

        if ($valuation === null) {
            return;
        }

        app(AccountValuationService::class)->delete($valuation);

        if ($this->editingValuationId === $valuation->id) {
            $this->editingValuationId = null;
        }

        $this->reconcilePreview = null;
    }

    /** Converts an untracked account into a tracked manual account; its transactions are kept. */
    public function trackAccount(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || $account->is_tracked) {
            return;
        }

        // The account becomes tracked first so its own rows count as real candidates. A hidden
        // transfer is never re-exposed: a mirror is replaced only when exactly one real
        // opposite row exists, otherwise the pair stays (the mirror becomes a manual row).
        DB::transaction(function () use ($account): void {
            // Same account lock as TransferLinker::linkToUntrackedAccount: a mirror being
            // created concurrently either lands before this (and is kept/replaced) or is refused.
            $locked = Account::query()->whereKey($account->id)->lockForUpdate()->first();

            if (! $locked instanceof Account || $locked->is_tracked) {
                return;
            }

            $locked->update(['is_tracked' => true]);
            app(TransferLinker::class)->releaseMirrors($locked);
        });
    }

    public function disableAccount(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || $account->status === AccountStatus::Inactive) {
            return;
        }

        $account->update(['status' => AccountStatus::Inactive, 'disabled_at' => CarbonImmutable::today()]);
    }

    public function enableAccount(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || $account->status !== AccountStatus::Inactive) {
            return;
        }

        $account->update(['status' => AccountStatus::Active, 'disabled_at' => null]);
    }

    /** Detaches the bank feed link and keeps every transaction; the account is manual from then on. */
    public function unlinkAccount(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || ! $account->redbarkAccount()->exists()) {
            return;
        }

        Account::query()->getConnection()->transaction(function () use ($account): void {
            $account->redbarkAccount()->update(['account_id' => null, 'ignored' => true]);
            $account->update(['import_source' => ImportSource::Manual, 'balance_source' => ImportSource::Manual]);
        });
    }

    public function confirmDelete(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if (! $account) {
            return;
        }

        $this->resetErrorBag('delete');
        $this->deletingAccountId = $account->id;
        $this->deletingAccountName = $account->name;
        $this->deletingTransactionCount = $account->transactions()->count();
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deletingAccountId) {
            return;
        }

        $account = $this->findUserAccount($this->deletingAccountId);

        if ($account?->redbarkAccount()->exists()) {
            $this->addError('delete', __('This account is linked to a bank feed. Unlink it first, then delete it.'));

            return;
        }

        $account?->delete();

        $this->showDeleteModal = false;
        $this->deletingAccountId = null;
        $this->deletingAccountName = '';
        $this->deletingTransactionCount = 0;
    }

    public function render(): View
    {
        $accounts = auth()->user()
            ->accounts()
            ->withLastMonthReconciliation()
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Account $account): int => match ($account->group) {
                AccountGroup::DayToDay => 0,
                AccountGroup::LongTermSavings => 1,
                AccountGroup::Hidden => 2,
            });

        $grouped = $accounts->groupBy(fn (Account $account) => $account->group->value);

        $today = CarbonImmutable::today();
        $netWorth = app(NetWorthService::class);
        $trends = [];

        foreach ($netWorth->accountSeries(auth()->user(), $accounts->pluck('id')->all(), $today->subDays(29), $today) as $accountId => $series) {
            $trends[$accountId] = ['points' => $series, 'favourable' => $netWorth->isFavourable($series)];
        }

        return view('livewire.account-manager', [
            'grouped' => $grouped,
            'trends' => $trends,
            'formatMoney' => MoneyCast::format(...),
            'accountTypes' => AccountClass::cases(),
            'currencies' => $this->authenticatedUser()->allowedCurrencies(),
            'accountGroups' => AccountGroup::cases(),
            'reconciliations' => $this->reconciliations(),
            'selectedType' => AccountClass::tryFrom($this->type),
            'rateTypes' => LoanRateType::cases(),
        ]);
    }

    private function saveUntracked(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'balance' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::enum(AccountClass::class)],
            'currency' => $this->currencyRules(),
        ] + $this->detailRules());

        $type = AccountClass::from($validated['type']);
        $cents = (int) round((float) $validated['balance'] * 100);
        $description = $validated['description'] ?: null;

        if ($this->editingAccountId) {
            $account = $this->findUserAccount($this->editingAccountId);

            if ($account === null || $account->is_tracked) {
                return;
            }

            try {
                DB::transaction(function () use ($account, $validated, $type, $description, $cents): void {
                    $account->update(['name' => $validated['name'], 'type' => $type, 'description' => $description]);
                    $this->saveAccountDetail($account, $type, $validated);

                    if ($cents !== $account->balance) {
                        app(AccountValuationService::class)->setCurrentBalance($account, $cents);
                    }
                });
            } catch (InvalidValuationException $exception) {
                $this->addError('balance', $exception->getMessage());

                return;
            }
        } else {
            Account::query()->getConnection()->transaction(function () use ($validated, $type, $cents, $description): void {
                $account = app(UntrackedAccountCreator::class)->create(
                    $this->authenticatedUser(),
                    $validated['name'],
                    $type,
                    $cents,
                    $description,
                    $validated['currency'],
                );

                $this->saveAccountDetail($account, $type, $validated);
                app(AccountBalanceHistoryBuilder::class)->rebuild($account);
            });
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingAccountId = null;
        $this->openedBalance = null;
        $this->openedOpeningBalance = null;
        $this->openedOpeningBalanceDate = null;
        $this->name = '';
        $this->balance = '';
        $this->hasCreditLimit = false;
        $this->credit_limit = '';
        $this->description = '';
        $this->excludeFromReports = false;
        $this->type = '';
        $this->group = '';
        $this->institution = '';
        $this->currency = '';
        $this->isUntracked = false;
        $this->balanceReadOnly = false;
        $this->rateType = '';
        $this->rate = '';
        $this->termMonths = '';
        $this->startDate = '';
        $this->originalPrincipal = '';
        $this->downPayment = '';
        $this->insurance = '';
        $this->minimumPayment = '';
        $this->annualFee = '';
        $this->openingBalance = '';
        $this->openingBalanceDate = '';
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    private function formRules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'balance' => ['required', 'numeric'],
            'credit_limit' => [$this->hasCreditLimit ? 'required' : 'nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'excludeFromReports' => ['boolean'],
            'type' => ['required', Rule::enum(AccountClass::class)],
            'group' => ['required', Rule::enum(AccountGroup::class)],
            'institution' => ['nullable', 'string', 'max:255'],
            'currency' => $this->currencyRules(),
        ];

        if (! ($this->editingAccountId === null && AccountClass::tryFrom($this->type)?->amortises())) {
            $rules['openingBalance'] = ['nullable', 'numeric'];
            $rules['openingBalanceDate'] = ['nullable', 'date', 'before_or_equal:today'];
        }

        return $rules + $this->detailRules();
    }

    /** @return array<string, mixed> */
    private function detailRules(): array
    {
        $type = AccountClass::tryFrom($this->type);

        if ($type?->amortises()) {
            $required = $this->editingAccountId === null && ! $this->isUntracked ? 'required' : 'nullable';

            return [
                'originalPrincipal' => [$required, 'numeric', 'gt:0', 'max:9999999999.99'],
                'startDate' => [$required, 'date', 'before_or_equal:today'],
                'rateType' => ['nullable', Rule::enum(LoanRateType::class)],
                'rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
                'termMonths' => ['nullable', 'integer', 'min:1', 'max:600'],
                'downPayment' => ['nullable', 'numeric', 'min:0'],
                'insurance' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        if ($type === AccountClass::CreditCard) {
            return [
                'minimumPayment' => ['nullable', 'numeric', 'min:0'],
                'annualFee' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        return [];
    }

    /**
     * Writes the type-specific details through the already user-scoped account. A type with no
     * details leaves any existing row alone, and an all-empty form never creates an empty row.
     *
     * @param  array<string, mixed>  $validated
     */
    private function saveAccountDetail(Account $account, AccountClass $type, array $validated): void
    {
        if ($type->amortises()) {
            $attributes = [
                'rate_type' => $this->nullableString($validated['rateType'] ?? null),
                'rate' => $this->nullableString($validated['rate'] ?? null),
                'term_months' => $this->nullableString($validated['termMonths'] ?? null) !== null ? (int) $validated['termMonths'] : null,
                'start_date' => $this->nullableString($validated['startDate'] ?? null),
                'original_principal' => $this->nullableCents($validated['originalPrincipal'] ?? null),
                'down_payment' => $this->nullableCents($validated['downPayment'] ?? null),
                'insurance' => $this->nullableCents($validated['insurance'] ?? null),
                'minimum_payment' => null,
                'annual_fee' => null,
            ];
        } elseif ($type === AccountClass::CreditCard) {
            $attributes = [
                'rate_type' => null,
                'rate' => null,
                'term_months' => null,
                'start_date' => null,
                'original_principal' => null,
                'down_payment' => null,
                'insurance' => null,
                'minimum_payment' => $this->nullableCents($validated['minimumPayment'] ?? null),
                'annual_fee' => $this->nullableCents($validated['annualFee'] ?? null),
            ];
        } else {
            return;
        }

        if ($account->detail === null && array_filter($attributes, fn (mixed $value): bool => $value !== null) === []) {
            return;
        }

        $account->detail()->updateOrCreate([], $attributes);
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableCents(mixed $value): ?int
    {
        $value = $this->nullableString($value);

        return $value === null ? null : $this->reconcileCents($value);
    }

    private function centsToInput(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }

    /** @return list<mixed> */
    private function currencyRules(): array
    {
        return $this->editingAccountId
            ? ['nullable']
            : ['required', 'string', Rule::in($this->authenticatedUser()->allowedCurrencies())];
    }

    private function authenticatedUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function findUserAccount(int $accountId): ?Account
    {
        return Account::query()
            ->where('user_id', auth()->id())
            ->find($accountId);
    }

    private function reconcilingAccount(): ?Account
    {
        $account = $this->reconcilingAccountId ? $this->findUserAccount($this->reconcilingAccountId) : null;

        return $account !== null && $account->isManuallyBalanced() ? $account : null;
    }

    private function findReconciliation(int $valuationId): ?AccountValuation
    {
        if ($this->reconcilingAccountId === null) {
            return null;
        }

        return AccountValuation::query()
            ->forUser((int) auth()->id())
            ->where('account_id', $this->reconcilingAccountId)
            ->where('kind', ValuationKind::Reconciliation)
            ->find($valuationId);
    }

    private function editingValuation(): ?AccountValuation
    {
        return $this->editingValuationId === null ? null : $this->findReconciliation($this->editingValuationId);
    }

    /** @return Collection<int, AccountValuation> */
    private function reconciliations(): Collection
    {
        if ($this->reconcilingAccountId === null) {
            return new Collection;
        }

        return AccountValuation::query()
            ->forUser((int) auth()->id())
            ->where('account_id', $this->reconcilingAccountId)
            ->where('kind', ValuationKind::Reconciliation)
            ->orderByDesc('valued_on')
            ->get();
    }

    /** @return array{reconcileBalance: string, reconcileDate: string} */
    private function validateReconcileForm(): array
    {
        /** @var array{reconcileBalance: string, reconcileDate: string} */
        return $this->validate([
            'reconcileBalance' => ['required', 'numeric'],
            'reconcileDate' => ['required', 'date', 'before_or_equal:today'],
        ]);
    }

    private function reconcileCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function closeReconcileModal(): void
    {
        $this->showReconcileModal = false;
        $this->reconcilingAccountId = null;
        $this->reconcilingAccountName = '';
        $this->reconcileBalance = '';
        $this->reconcileDate = '';
        $this->editingValuationId = null;
        $this->reconcilePreview = null;
    }

    /** @return array{balance: int, balance_source: ImportSource, balance_updated_at: CarbonImmutable} */
    private function manualBalanceAttributes(int $cents): array
    {
        return [
            'balance' => $cents,
            'balance_source' => ImportSource::Manual,
            'balance_updated_at' => CarbonImmutable::now(),
        ];
    }

    private function saveOpeningBalance(Account $account): bool
    {
        if ($this->openingBalance === '') {
            return false;
        }

        $cents = $this->reconcileCents($this->openingBalance);
        $date = $this->openingBalanceDate !== '' ? $this->openingBalanceDate : null;

        if ($cents === $this->openedOpeningBalance && $date === $this->openedOpeningBalanceDate) {
            return false;
        }

        $on = $date !== null ? CarbonImmutable::parse($date) : null;

        app(AccountValuationService::class)->setOpeningBalance($account, $cents, $on);

        return $cents !== $this->openedOpeningBalance;
    }
}
