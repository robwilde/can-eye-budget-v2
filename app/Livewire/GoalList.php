<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\GoalProgressDto;
use App\Enums\AccountClass;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\User;
use App\Services\GoalManager;
use App\Services\GoalOverview;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The goals list with its summary strip, filters, search, archived section and the goal form.
 *
 * @property-read array{rows: list<array{goal: Goal, progress: GoalProgressDto}>, summary: array{inflow: int, priorInflow: int, onTrack: int, behind: int, neededPerMonth: int}} $overview
 * @property-read list<array{goal: Goal, progress: GoalProgressDto}> $rows
 * @property-read list<array{goal: Goal, progress: GoalProgressDto}> $archivedRows
 * @property-read bool $searchable
 * @property-read Collection<int, Account> $accountOptions
 */
final class GoalList extends Component
{
    public const int SEARCH_THRESHOLD = 6;

    public const string FILTER_ALL = 'all';

    public const string FILTER_ACTIVE = 'active';

    public const string FILTER_PAUSED = 'paused';

    public const string FILTER_COMPLETED = 'completed';

    #[Url(except: self::FILTER_ALL)]
    public string $filter = self::FILTER_ALL;

    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $kind = 'one_off';

    public string $targetAmount = '';

    public string $targetDate = '';

    public string $monthsOfExpenses = '';

    public string $colour = '';

    public string $icon = '';

    public string $notes = '';

    /** @var list<string> */
    public array $accountIds = [];

    /** @var array<string, string> */
    public array $earmarks = [];

    public function boot(): void
    {
        abort_unless(config('budget.goals_enabled') === true, 404);
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, [self::FILTER_ALL, self::FILTER_ACTIVE, self::FILTER_PAUSED, self::FILTER_COMPLETED], true)
            ? $this->filter
            : self::FILTER_ALL;
    }

    public function startCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $goalId): void
    {
        $user = $this->authenticatedUser();
        $goal = Goal::query()->where('user_id', $user->id)->findOrFail($goalId);

        $this->resetForm();
        $this->editingId = $goal->id;
        $this->name = $goal->name;
        $this->kind = $goal->kind->value;
        $this->targetAmount = number_format($goal->target_amount / 100, 2, '.', '');
        $this->targetDate = $goal->target_date?->toDateString() ?? '';
        $this->monthsOfExpenses = (string) ($goal->target_months_of_expenses ?? '');
        $this->colour = (string) $goal->colour;
        $this->icon = (string) $goal->icon;
        $this->notes = (string) $goal->notes;

        foreach (GoalAccount::query()->where('goal_id', $goal->id)->get() as $link) {
            $this->accountIds[] = (string) $link->account_id;
            $this->earmarks[(string) $link->account_id] = $link->earmark_amount === null ? '' : number_format($link->earmark_amount / 100, 2, '.', '');
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $user = $this->authenticatedUser();
        $this->resetErrorBag();

        $reserve = $this->kind === GoalKind::Reserve->value;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', 'in:one_off,reserve'],
            'targetAmount' => [$reserve && filled($this->monthsOfExpenses) ? 'nullable' : 'required', 'numeric', 'min:0', 'max:1000000000'],
            'targetDate' => ['nullable', 'date'],
            'monthsOfExpenses' => ['nullable', 'integer', 'min:1', 'max:120'],
            'colour' => ['nullable', 'string', 'max:7'],
            'icon' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $accounts = [];

        foreach ($this->accountIds as $accountId) {
            $earmark = mb_trim($this->earmarks[$accountId] ?? '');

            if ($earmark !== '' && (! is_numeric($earmark) || (float) $earmark < 0)) {
                $this->addError('goal', __('An earmark must be an amount of zero or more.'));

                return;
            }

            $accounts[] = ['account_id' => (int) $accountId, 'earmark_amount' => $earmark === '' ? null : (int) round((float) $earmark * 100)];
        }

        $data = [
            'name' => $validated['name'],
            'kind' => GoalKind::from($validated['kind']),
            'target_amount' => (int) round((float) ($validated['targetAmount'] ?? 0) * 100),
            'target_date' => filled($validated['targetDate'] ?? null) ? $validated['targetDate'] : null,
            'target_months_of_expenses' => $reserve && filled($validated['monthsOfExpenses'] ?? null) ? (int) $validated['monthsOfExpenses'] : null,
            'colour' => filled($validated['colour'] ?? null) ? $validated['colour'] : null,
            'icon' => filled($validated['icon'] ?? null) ? $validated['icon'] : null,
            'notes' => filled($validated['notes'] ?? null) ? $validated['notes'] : null,
            'accounts' => $accounts,
        ];

        try {
            $manager = app(GoalManager::class);

            if ($this->editingId === null) {
                $manager->create($user, $data);
            } else {
                $manager->update($user, Goal::query()->where('user_id', $user->id)->findOrFail($this->editingId), $data);
            }
        } catch (GoalRefusedException $refused) {
            $this->addError('goal', $refused->getMessage());

            return;
        }

        $this->resetForm();
        $this->showForm = false;
        $this->invalidate();
        Flux::toast(text: __('Goal saved'), variant: 'success');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    /** @return array{rows: list<array{goal: Goal, progress: GoalProgressDto}>, summary: array{inflow: int, priorInflow: int, onTrack: int, behind: int, neededPerMonth: int}} */
    #[Computed]
    public function overview(): array
    {
        return app(GoalOverview::class)->for($this->authenticatedUser());
    }

    /**
     * Non-archived goals narrowed by the filter chip and, when it is offered, the search.
     *
     * @return list<array{goal: Goal, progress: GoalProgressDto}>
     */
    #[Computed]
    public function rows(): array
    {
        $term = mb_strtolower(mb_trim($this->search));

        return array_values(array_filter($this->overview['rows'], function (array $row) use ($term): bool {
            $status = $row['goal']->status;

            return $status !== GoalStatus::Archived
                && match ($this->filter) {
                    self::FILTER_ACTIVE => $status === GoalStatus::Active,
                    self::FILTER_PAUSED => $status === GoalStatus::Paused,
                    self::FILTER_COMPLETED => $status === GoalStatus::Completed,
                    default => true,
                }
            && ($term === '' || ! $this->searchable || str_contains(mb_strtolower($row['goal']->name), $term));
        }));
    }

    /** @return list<array{goal: Goal, progress: GoalProgressDto}> */
    #[Computed]
    public function archivedRows(): array
    {
        return array_values(array_filter($this->overview['rows'], static fn (array $row): bool => $row['goal']->status === GoalStatus::Archived));
    }

    #[Computed]
    public function searchable(): bool
    {
        return count($this->overview['rows']) - count($this->archivedRows) > self::SEARCH_THRESHOLD;
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accountOptions(): Collection
    {
        return Account::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->whereIn('type', array_map(static fn (AccountClass $type): string => $type->value, [
                AccountClass::Transaction, AccountClass::Savings, AccountClass::TermDeposit, AccountClass::Foreign, AccountClass::Investment,
            ]))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency']);
    }

    public function render(): View
    {
        return view('livewire.goal-list');
    }

    private function resetForm(): void
    {
        $this->reset('name', 'kind', 'targetAmount', 'targetDate', 'monthsOfExpenses', 'colour', 'icon', 'notes', 'accountIds', 'earmarks', 'editingId');
        $this->resetErrorBag();
    }

    private function invalidate(): void
    {
        unset($this->overview, $this->rows, $this->archivedRows, $this->searchable); // @phpstan-ignore property.notFound
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
