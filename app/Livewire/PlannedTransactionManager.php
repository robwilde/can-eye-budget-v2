<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

final class PlannedTransactionManager extends Component
{
    public ?int $accountId = null;

    public bool $showEditModal = false;

    public ?int $editingId = null;

    public string $amount = '';

    public string $frequency = '';

    public int|string|null $categoryId = null;

    public string $direction = '';

    public bool $isActive = true;

    public ?string $untilDate = null;

    public bool $showDeleteModal = false;

    public ?int $deletingId = null;

    public string $deletingDescription = '';

    public function mount(): void
    {
        $user = $this->authenticatedUser();
        $firstAccountId = $user->accounts()->orderBy('id')->value('id');

        $this->accountId = $user->primary_account_id ?? ($firstAccountId === null ? null : (int) $firstAccountId);
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
        $this->direction = $plan->direction->value;
        $this->isActive = $plan->is_active;
        $this->untilDate = $plan->until_date?->format('Y-m-d');
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

        DB::transaction(function () use ($plan): void {
            $plan->update([
                'amount' => (int) round((float) $this->amount * 100),
                'frequency' => $this->frequency,
                'category_id' => $this->resolvedCategoryId(),
                'direction' => $this->direction,
                'until_date' => $this->untilDate !== null && $this->untilDate !== '' ? $this->untilDate : null,
                'is_active' => $this->isActive,
            ]);
        });

        Flux::toast(text: 'Planned transaction updated', variant: 'success');

        $this->showEditModal = false;
        $this->resetEditForm();
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

        return view('livewire.planned-transaction-manager', [
            'accounts' => $this->accountsForUser($user),
            'plans' => $this->plannedTransactionsForSelectedAccount($user),
            'categories' => Category::visibleSortedByFullPath(),
            'frequencies' => RecurrenceFrequency::cases(),
            'directions' => TransactionDirection::cases(),
        ]);
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

        Flux::toast(text: 'Pay cycle income is managed elsewhere', variant: 'warning');

        return false;
    }

    /** @return Collection<int, Account> */
    private function accountsForUser(User $user): Collection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, PlannedTransaction> */
    private function plannedTransactionsForSelectedAccount(User $user): Collection
    {
        if (! $this->selectedAccountBelongsTo($user)) {
            return collect();
        }

        $today = CarbonImmutable::today();

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

        $plans->each(function (PlannedTransaction $plan) use ($today): void {
            $plan->setAttribute('next_occurrence', $plan->nextOccurrenceOnOrAfter($today));
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
            ->whereKey($this->accountId)
            ->exists();
    }

    /** @return array<string, list<mixed>> */
    private function formRules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'frequency' => ['required', 'string', Rule::in($this->frequencyValues())],
            'direction' => ['required', 'string', Rule::in($this->directionValues())],
            'untilDate' => ['nullable', 'date'],
        ];
    }

    private function resolvedCategoryId(): ?int
    {
        if ($this->categoryId === null || $this->categoryId === '') {
            return null;
        }

        $categoryId = (int) $this->categoryId;

        return Category::query()
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
        $this->direction = '';
        $this->isActive = true;
        $this->untilDate = null;
    }

    private function resetDeleteForm(): void
    {
        $this->deletingId = null;
        $this->deletingDescription = '';
    }
}
