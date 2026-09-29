<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\User;
use App\Services\Transfers\TransferLinker;
use App\Services\Transfers\UntrackedAccountCreator;
use Carbon\CarbonImmutable;
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

    public bool $showReconcileModal = false;

    public ?int $reconcilingAccountId = null;

    public string $reconcilingAccountName = '';

    public string $reconcileBalance = '';

    public string $reconcileDate = '';

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
        $this->hasCreditLimit = $account->credit_limit !== null;
        $this->credit_limit = $account->credit_limit !== null
            ? number_format($account->credit_limit / 100, 2, '.', '')
            : '';
        $this->description = $account->description ?? '';
        $this->type = $account->type->value;
        $this->group = $account->group->value;
        $this->institution = $account->institution ?? '';
        $this->isUntracked = ! $account->is_tracked;
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

        $validated = $this->validate($this->formRules());

        $mutableData = [
            'name' => $validated['name'],
            'balance' => (int) round((float) $validated['balance'] * 100),
            'balance_source' => ImportSource::Manual,
            'balance_updated_at' => now(),
            'type' => $validated['type'],
            'group' => $validated['group'],
            'institution' => $validated['institution'] ?: null,
            'description' => $validated['description'] ?: null,
        ];

        if ($this->hasCreditLimit && $validated['credit_limit'] !== null && $validated['credit_limit'] !== '') {
            $mutableData['credit_limit'] = (int) round((float) $validated['credit_limit'] * 100);
        } else {
            $mutableData['credit_limit'] = null;
        }

        if ($this->editingAccountId) {
            $account = $this->findUserAccount($this->editingAccountId);

            $account?->update($mutableData);
        } else {
            Account::query()->create($mutableData + [
                'user_id' => auth()->id(),
                'currency' => 'AUD',
                'status' => AccountStatus::Active,
            ]);
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    public function openReconcileModal(int $accountId): void
    {
        $account = $this->findUserAccount($accountId);

        if ($account === null || $account->is_tracked) {
            return;
        }

        $this->resetValidation();
        $this->reconcilingAccountId = $account->id;
        $this->reconcilingAccountName = $account->name;
        $this->reconcileBalance = number_format($account->balance / 100, 2, '.', '');
        $this->reconcileDate = CarbonImmutable::today()->toDateString();
        $this->showReconcileModal = true;
    }

    public function reconcile(): void
    {
        $validated = $this->validate([
            'reconcileBalance' => ['required', 'numeric'],
            'reconcileDate' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $account = $this->reconcilingAccountId ? $this->findUserAccount($this->reconcilingAccountId) : null;

        if ($account === null || $account->is_tracked) {
            return;
        }

        $account->reconcileManualBalance(
            (int) round((float) $validated['reconcileBalance'] * 100),
            CarbonImmutable::parse($validated['reconcileDate']),
        );

        $this->showReconcileModal = false;
        $this->reconcilingAccountId = null;
        $this->reconcilingAccountName = '';
        $this->reconcileBalance = '';
        $this->reconcileDate = '';
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

            $account->update(['name' => $validated['name'], 'type' => $type, 'description' => $description]);

            if ($cents !== $account->balance) {
                $account->reconcileManualBalance($cents);
            }
        } else {
            app(UntrackedAccountCreator::class)->create(
                $this->authenticatedUser(),
                $validated['name'],
                $type,
                $cents,
                $description,
            );
        }

        $this->showFormModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingAccountId = null;
        $this->name = '';
        $this->balance = '';
        $this->hasCreditLimit = false;
        $this->credit_limit = '';
        $this->description = '';
        $this->type = '';
        $this->group = '';
        $this->institution = '';
        $this->isUntracked = false;
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
}
