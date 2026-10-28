<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Enums\ValuationKind;
use App\Exceptions\InvalidValuationException;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\User;
use App\Services\AccountValuationService;
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

    public ?int $editingAccountId = null;

    public ?int $deletingAccountId = null;

    public string $deletingAccountName = '';

    public int $deletingTransactionCount = 0;

    public string $name = '';

    public string $balance = '';

    public bool $hasCreditLimit = false;

    public string $credit_limit = '';

    public string $description = '';

    public string $type = '';

    public string $group = '';

    public string $institution = '';

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

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->group = AccountGroup::DayToDay->value;
        $this->type = AccountClass::Transaction->value;
        $this->showFormModal = true;
    }

    public function openAddUntrackedModal(): void
    {
        $this->resetForm();
        $this->isUntracked = true;
        $this->group = AccountGroup::LongTermSavings->value;
        $this->type = AccountClass::Savings->value;
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
        $this->type = $account->type->value;
        $this->group = $account->group->value;
        $this->institution = $account->institution ?? '';

        $opening = $account->valuations()->where('kind', ValuationKind::OpeningBalance)->first();
        $this->openingBalance = $opening !== null ? number_format($opening->balance / 100, 2, '.', '') : '';
        $this->openingBalanceDate = $opening?->valued_on->toDateString() ?? '';
        $this->openedOpeningBalance = $opening?->balance;
        $this->openedOpeningBalanceDate = $opening?->valued_on->toDateString();
        $this->isUntracked = ! $account->is_tracked;
        $this->balanceReadOnly = ! $account->isManuallyBalanced();
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
        ];

        if ($this->hasCreditLimit && $validated['credit_limit'] !== null && $validated['credit_limit'] !== '') {
            $details['credit_limit'] = $this->reconcileCents($validated['credit_limit']);
        } else {
            $details['credit_limit'] = null;
        }

        $cents = $providerFed ? 0 : $this->reconcileCents($validated['balance']);
        $failedField = 'openingBalanceDate';

        try {
            DB::transaction(function () use ($account, $details, $cents, $providerFed, &$failedField): void {
                $balanceChanged = false;

                if ($account === null) {
                    $account = Account::query()->create($details + $this->manualBalanceAttributes($cents) + [
                        'user_id' => auth()->id(),
                        'currency' => 'AUD',
                        'status' => AccountStatus::Active,
                    ]);

                    app(AccountBalanceHistoryBuilder::class)->rebuild($account);
                } else {
                    $balanceChanged = ! $providerFed && $cents !== $this->openedBalance;
                    $account->update($details);
                }

                $this->saveOpeningBalance($account);

                if ($balanceChanged) {
                    $failedField = 'balance';
                    app(AccountValuationService::class)->setCurrentBalance($account, $cents);
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

    public function confirmDelete(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if (! $account) {
            return;
        }

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

        return view('livewire.account-manager', [
            'grouped' => $grouped,
            'formatMoney' => MoneyCast::format(...),
            'accountTypes' => AccountClass::cases(),
            'accountGroups' => AccountGroup::cases(),
            'reconciliations' => $this->reconciliations(),
        ]);
    }

    private function saveUntracked(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'balance' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::enum(AccountClass::class)],
        ]);

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
                );

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
        $this->type = '';
        $this->group = '';
        $this->institution = '';
        $this->isUntracked = false;
        $this->balanceReadOnly = false;
        $this->openingBalance = '';
        $this->openingBalanceDate = '';
        $this->resetValidation();
    }

    /** @return array<string, mixed> */
    private function formRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'balance' => ['required', 'numeric'],
            'credit_limit' => [$this->hasCreditLimit ? 'required' : 'nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::enum(AccountClass::class)],
            'group' => ['required', Rule::enum(AccountGroup::class)],
            'institution' => ['nullable', 'string', 'max:255'],
            'openingBalance' => ['nullable', 'numeric'],
            'openingBalanceDate' => ['nullable', 'date', 'before_or_equal:today'],
        ];
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

    private function saveOpeningBalance(Account $account): void
    {
        if ($this->openingBalance === '') {
            return;
        }

        $cents = $this->reconcileCents($this->openingBalance);
        $date = $this->openingBalanceDate !== '' ? $this->openingBalanceDate : null;

        if ($cents === $this->openedOpeningBalance && $date === $this->openedOpeningBalanceDate) {
            return;
        }

        $on = $date !== null ? CarbonImmutable::parse($date) : null;

        app(AccountValuationService::class)->setOpeningBalance($account, $cents, $on);
    }
}
