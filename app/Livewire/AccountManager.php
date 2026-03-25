<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->group = AccountGroup::DayToDay->value;
        $this->type = AccountClass::Transaction->value;
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
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate($this->formRules());

        $mutableData = [
            'name' => $validated['name'],
            'balance' => (int) round((float) $validated['balance'] * 100),
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

    private function findUserAccount(int $accountId): ?Account
    {
        return Account::query()
            ->where('user_id', auth()->id())
            ->find($accountId);
    }
}
