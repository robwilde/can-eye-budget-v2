<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\GoalProgressDto;
use App\DTOs\GoalProjectionDto;
use App\Enums\GoalPledgeStatus;
use App\Exceptions\GoalRefusedException;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalPledge;
use App\Models\User;
use App\Services\GoalManager;
use App\Services\GoalPledgeManager;
use App\Services\GoalProgressCalculator;
use App\Services\GoalProjection;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One goal: progress, projection, per-account funding, pledges and lifecycle actions.
 *
 * @property-read Goal $goal
 * @property-read GoalProgressDto $progress
 * @property-read GoalProjectionDto $projection
 * @property-read list<array{account: Account, earmark: int|null, backing: int}> $funding
 * @property-read Collection<int, GoalPledge> $pledges
 * @property-read int|null $lastMatchedDays
 */
final class GoalDetail extends Component
{
    #[Locked]
    public int $goalId;

    public string $spendAmount = '';

    public string $spendAccountId = '';

    public string $pledgeAmount = '';

    public string $pledgeAccountId = '';

    public function boot(): void
    {
        abort_unless(config('budget.goals_enabled') === true, 404);
    }

    public function mount(int $goalId): void
    {
        $this->goalId = Goal::query()->where('user_id', $this->authenticatedUser()->id)->findOrFail($goalId)->id;
    }

    public function pause(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->pause($user, $goal));
    }

    public function resume(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->resume($user, $goal));
    }

    public function complete(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->complete($user, $goal));
    }

    public function reopen(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->reopen($user, $goal));
    }

    public function archive(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->archive($user, $goal));
    }

    public function unarchive(): void
    {
        $this->lifecycle(fn (User $user, Goal $goal) => app(GoalManager::class)->unarchive($user, $goal));
    }

    public function delete(): mixed
    {
        $user = $this->authenticatedUser();
        $goal = $this->loadGoal($user);
        $this->resetErrorBag();

        try {
            app(GoalManager::class)->delete($user, $goal);
        } catch (GoalRefusedException $refused) {
            $this->addError('goal', $refused->getMessage());

            return null;
        }

        return $this->redirectRoute('goals', navigate: true);
    }

    /**
     * Records a spend in dollars against one of the goal's accounts.
     */
    public function recordSpend(): void
    {
        $user = $this->authenticatedUser();
        $goal = $this->loadGoal($user);
        $this->resetErrorBag();
        $cents = $this->cents($this->spendAmount);

        if ($cents === null || $cents === 0) {
            $this->addError('goal', __('Enter a spend greater than zero.'));

            return;
        }

        $account = $this->linkedAccount($goal, $this->spendAccountId);

        if ($this->spendAccountId !== '' && $account === null) {
            $this->addError('goal', __('That account does not fund this goal.'));

            return;
        }

        $this->attempt(function () use ($user, $goal, $cents, $account): void {
            app(GoalManager::class)->recordSpend($user, $goal, $cents, $account);
            $this->reset('spendAmount', 'spendAccountId');
            Flux::toast(text: __('Spend recorded'), variant: 'success');
        });
    }

    /**
     * Pledges money just saved or transferred to one of the goal's accounts, in dollars.
     */
    public function pledge(): void
    {
        $user = $this->authenticatedUser();
        $goal = $this->loadGoal($user);
        $this->resetErrorBag();
        $cents = $this->cents($this->pledgeAmount);
        $account = $this->linkedAccount($goal, $this->pledgeAccountId);

        if ($cents === null || $cents === 0 || $account === null) {
            $this->addError('goal', __('Choose an account and an amount above zero.'));

            return;
        }

        $this->attempt(function () use ($user, $goal, $cents, $account): void {
            app(GoalPledgeManager::class)->pledge($user, $goal, $account, $cents);
            $this->reset('pledgeAmount', 'pledgeAccountId');
            Flux::toast(text: __('Pledge noted'), variant: 'success');
        });
    }

    public function renewPledge(int $pledgeId): void
    {
        $user = $this->authenticatedUser();
        $pledge = $this->loadPledge($user, $pledgeId);
        $this->resetErrorBag();

        $this->attempt(fn () => app(GoalPledgeManager::class)->renew($user, $pledge));
    }

    public function cancelPledge(int $pledgeId): void
    {
        $user = $this->authenticatedUser();
        $pledge = $this->loadPledge($user, $pledgeId);
        $this->resetErrorBag();

        $this->attempt(fn () => app(GoalPledgeManager::class)->cancel($user, $pledge));
    }

    #[Computed]
    public function goal(): Goal
    {
        return $this->loadGoal($this->authenticatedUser());
    }

    #[Computed]
    public function progress(): GoalProgressDto
    {
        return app(GoalProgressCalculator::class)->calculate($this->goal);
    }

    #[Computed]
    public function projection(): GoalProjectionDto
    {
        return app(GoalProjection::class)->for($this->goal, $this->progress);
    }

    /** @return list<array{account: Account, earmark: int|null, backing: int}> */
    #[Computed]
    public function funding(): array
    {
        $backings = app(GoalProgressCalculator::class)->accountBackings($this->goal);
        $rows = [];

        foreach (GoalAccount::query()->where('goal_id', $this->goal->id)->with('account')->orderBy('id')->get() as $link) {
            $rows[] = ['account' => $link->account, 'earmark' => $link->earmark_amount, 'backing' => $backings[$link->account_id] ?? 0];
        }

        return $rows;
    }

    /** @return Collection<int, GoalPledge> */
    #[Computed]
    public function pledges(): Collection
    {
        return GoalPledge::query()
            ->where('goal_id', $this->goal->id)
            ->where('status', GoalPledgeStatus::Open)
            ->where('expires_at', '>', now())
            ->with('account:id,name')
            ->orderBy('expires_at')
            ->get();
    }

    #[Computed]
    public function lastMatchedDays(): ?int
    {
        $matched = GoalPledge::query()
            ->where('goal_id', $this->goal->id)
            ->where('status', GoalPledgeStatus::Matched)
            ->max('updated_at');

        if ($matched === null) {
            return null;
        }

        $user = $this->authenticatedUser();
        $localDay = CarbonImmutable::parse((string) $matched)->setTimezone($user->resolvedTimezone())->startOfDay();

        return (int) abs($localDay->diffInDays($user->today()->startOfDay()));
    }

    public function render(): View
    {
        return view('livewire.goal-detail');
    }

    /**
     * @param  Closure(User, Goal): mixed  $change
     */
    private function lifecycle(Closure $change): void
    {
        $user = $this->authenticatedUser();
        $goal = $this->loadGoal($user);
        $this->resetErrorBag();

        $this->attempt(fn () => $change($user, $goal));
    }

    private function attempt(Closure $write): void
    {
        try {
            $write();
        } catch (GoalRefusedException $refused) {
            $this->addError('goal', $refused->getMessage());
        }

        unset($this->goal, $this->progress, $this->projection, $this->funding, $this->pledges, $this->lastMatchedDays); // @phpstan-ignore property.notFound
    }

    private function loadGoal(User $user): Goal
    {
        return Goal::query()->where('user_id', $user->id)->findOrFail($this->goalId);
    }

    private function loadPledge(User $user, int $pledgeId): GoalPledge
    {
        $this->loadGoal($user);

        return GoalPledge::query()->where('goal_id', $this->goalId)->findOrFail($pledgeId);
    }

    private function linkedAccount(Goal $goal, string $accountId): ?Account
    {
        if (! ctype_digit($accountId)) {
            return null;
        }

        return Account::query()
            ->where('user_id', $goal->user_id)
            ->whereIn('id', GoalAccount::query()->where('goal_id', $goal->id)->select('account_id'))
            ->find((int) $accountId);
    }

    private function cents(string $value): ?int
    {
        $value = mb_trim($value);

        if ($value === '' || ! is_numeric($value) || (float) $value < 0 || (float) $value > 1_000_000_000) {
            return null;
        }

        return (int) round((float) $value * 100);
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
