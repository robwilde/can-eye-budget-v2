<?php

declare(strict_types=1);

use App\Enums\GoalHealth;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Goal;
use App\Models\GoalAccount;
use App\Models\GoalSpend;
use App\Models\Transaction;
use App\Models\User;
use App\Services\GoalManager;
use App\Services\GoalProgressCalculator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->calculator = app(GoalProgressCalculator::class);
});

function goalAccount(User $user, int $balance): Account
{
    return Account::factory()->for($user)->create(['balance' => $balance]);
}

function linkGoal(Goal $goal, Account $account, ?int $earmark = null): GoalAccount
{
    return GoalAccount::factory()->for($goal)->for($account)->create(['earmark_amount' => $earmark]);
}

function inflow(User $user, Account $account, int $amount, int $daysAgo = 10): Transaction
{
    return Transaction::factory()->for($user)->for($account)->credit()->create([
        'amount' => $amount,
        'post_date' => $user->today()->subDays($daysAgo),
    ]);
}

it('computes remaining, monthly target and on-track pace', function () {
    $goal = Goal::factory()->for($this->user)->create([
        'target_amount' => 100000,
        'target_date' => $this->user->today()->addDays(120),
    ]);
    $account = goalAccount($this->user, 40000);
    linkGoal($goal, $account);
    inflow($this->user, $account, 60000);

    $progress = $this->calculator->calculate($goal);

    expect($progress->savedAmount)->toBe(40000)
        ->and($progress->remainingAmount)->toBe(60000)
        ->and($progress->monthlyTarget)->toBe(15000)
        ->and($progress->pacePerMonth)->toBe(20000)
        ->and($progress->health)->toBe(GoalHealth::OnTrack);
});

it('freezes the saved amount when a goal is completed', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000]);
    $account = goalAccount($this->user, 100000);
    linkGoal($goal, $account);

    $completed = app(GoalManager::class)->complete($this->user, $goal);
    $account->update(['balance' => 70000]);

    expect($this->calculator->calculate($completed)->savedAmount)->toBe(100000);
});

it('reads behind when the pace cannot cover the remaining amount', function () {
    $goal = Goal::factory()->for($this->user)->create([
        'target_amount' => 100000,
        'target_date' => $this->user->today()->addDays(120),
    ]);
    linkGoal($goal, goalAccount($this->user, 40000));

    expect($this->calculator->calculate($goal)->health)->toBe(GoalHealth::Behind);
});

it('never reads a paused goal as behind', function () {
    $goal = Goal::factory()->paused()->for($this->user)->create([
        'target_amount' => 100000,
        'target_date' => $this->user->today()->addDays(120),
    ]);
    linkGoal($goal, goalAccount($this->user, 40000));

    expect($this->calculator->calculate($goal)->health)->toBe(GoalHealth::OnTrack);
});

it('reads a reserve as funded or depleted and never behind', function () {
    $reserve = Goal::factory()->reserve()->for($this->user)->create(['target_amount' => 100000]);
    $account = goalAccount($this->user, 100000);
    linkGoal($reserve, $account);

    $funded = $this->calculator->calculate($reserve);
    $account->update(['balance' => 99999]);
    $depleted = $this->calculator->calculate($reserve);

    expect($funded->health)->toBe(GoalHealth::Funded)
        ->and($depleted->health)->toBe(GoalHealth::Depleted)
        ->and($depleted->monthlyTarget)->toBeNull();
});

it('reports no target date for an open-ended one-off goal', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000, 'target_date' => null]);
    linkGoal($goal, goalAccount($this->user, 100));

    expect($this->calculator->calculate($goal)->health)->toBe(GoalHealth::NoTargetDate);
});

it('caps percent at 99 until nothing remains', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000]);
    $account = goalAccount($this->user, 99999);
    linkGoal($goal, $account);

    $almost = $this->calculator->calculate($goal);
    $account->update(['balance' => 100000]);
    $done = $this->calculator->calculate($goal);

    expect($almost->percent)->toBe(99)
        ->and($done->percent)->toBe(100)
        ->and($done->health)->toBe(GoalHealth::Reached);
});

it('scales over-earmarked accounts down pro rata', function () {
    $account = goalAccount($this->user, 1000);
    $first = Goal::factory()->for($this->user)->create();
    $second = Goal::factory()->for($this->user)->create();
    linkGoal($first, $account, 1500);
    linkGoal($second, $account, 500);

    expect($this->calculator->savedAmount($first))->toBe(750)
        ->and($this->calculator->savedAmount($second))->toBe(250);
});

it('lets an overdrawn account back nothing', function () {
    $goal = Goal::factory()->for($this->user)->create();
    linkGoal($goal, goalAccount($this->user, -5000));

    expect($this->calculator->savedAmount($goal))->toBe(0);
});

it('counts spends toward progress', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000]);
    $account = goalAccount($this->user, 20000);
    linkGoal($goal, $account);
    GoalSpend::factory()->for($goal)->for($account)->create(['amount' => 5000]);

    expect($this->calculator->savedAmount($goal))->toBe(25000);
});

it('counts a transfer from an unlinked account into a linked one as pace', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $checking = goalAccount($this->user, 0);
    $savings = goalAccount($this->user, 0);
    linkGoal($goal, $savings);
    $credit = inflow($this->user, $savings, 30000);
    $debit = Transaction::factory()->for($this->user)->for($checking)->debit()->create([
        'amount' => 30000,
        'post_date' => $this->user->today()->subDays(10),
        'transfer_pair_id' => $credit->id,
    ]);
    $credit->update(['transfer_pair_id' => $debit->id]);

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(10000);
});

it('nets a move between two accounts linked to the same goal to zero', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $first = goalAccount($this->user, 0);
    $second = goalAccount($this->user, 0);
    linkGoal($goal, $first);
    linkGoal($goal, $second);
    $credit = inflow($this->user, $second, 30000);
    $debit = Transaction::factory()->for($this->user)->for($first)->debit()->create([
        'amount' => 30000,
        'post_date' => $this->user->today()->subDays(10),
        'transfer_pair_id' => $credit->id,
    ]);
    $credit->update(['transfer_pair_id' => $debit->id]);

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(0);
});

it('leaves pending, excluded and out-of-window rows out of pace', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $account = goalAccount($this->user, 0);
    linkGoal($goal, $account);
    inflow($this->user, $account, 30000);
    inflow($this->user, $account, 90000, 100);
    Transaction::factory()->for($this->user)->for($account)->credit()->create([
        'amount' => 90000,
        'post_date' => $this->user->today()->subDays(5),
        'status' => TransactionStatus::Pending,
    ]);
    Transaction::factory()->for($this->user)->for($account)->credit()->create([
        'amount' => 90000,
        'post_date' => $this->user->today()->subDays(5),
        'excluded' => true,
    ]);

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(10000);
});

it('keeps the saved amount frozen after a completed goal is archived', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000]);
    $account = goalAccount($this->user, 100000);
    linkGoal($goal, $account);
    $manager = app(GoalManager::class);

    $manager->complete($this->user, $goal);
    $archived = $manager->archive($this->user, $goal);
    $account->update(['balance' => 70000]);

    expect($this->calculator->calculate($archived)->savedAmount)->toBe(100000);
});

it('counts reportable split children and leaves the excluded split parent out of pace', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $account = goalAccount($this->user, 0);
    linkGoal($goal, $account);
    $parent = Transaction::factory()->for($this->user)->for($account)->debit()->create([
        'amount' => 30000,
        'post_date' => $this->user->today()->subDays(10),
        'excluded' => true,
    ]);

    foreach ([10000, 20000] as $amount) {
        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => $amount,
            'post_date' => $this->user->today()->subDays(10),
            'split_parent_id' => $parent->id,
        ]);
    }

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(-10000);
});

function investmentWithHistory(User $user, int $balance, int $opening, int $inflows = 0, int $outflows = 0): Account
{
    $account = Account::factory()->for($user)->create(['balance' => $balance, 'type' => App\Enums\AccountClass::Investment]);
    App\Models\AccountDailyBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $user->today()->subDays(3)->toDateString(),
        'start_balance' => $opening,
        'end_balance' => $balance,
        'inflows' => $inflows,
        'outflows' => $outflows,
        'adjustments' => $balance - ($opening + $inflows - $outflows),
    ]);

    return $account;
}

it('counts investment contributions rather than market gains toward savings', function () {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 120000]);
    linkGoal($goal, investmentWithHistory($this->user, 120000, 100000));

    $progress = $this->calculator->calculate($goal);

    expect($progress->savedAmount)->toBe(100000)
        ->and($progress->health)->not->toBe(GoalHealth::Reached);
});

it('counts later contributions and withdrawals but still shows an investment loss', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $second = Goal::factory()->for($this->user)->create();
    $third = Goal::factory()->for($this->user)->create();
    linkGoal($goal, investmentWithHistory($this->user, 150000, 100000, inflows: 20000, outflows: 5000));
    linkGoal($second, investmentWithHistory($this->user, 80000, 100000));
    linkGoal($third, Account::factory()->for($this->user)->create(['balance' => 70000, 'type' => App\Enums\AccountClass::Investment]));

    expect($this->calculator->savedAmount($goal))->toBe(115000)
        ->and($this->calculator->savedAmount($second))->toBe(80000)
        ->and($this->calculator->savedAmount($third))->toBe(70000);
});

it('counts an explicit opening balance as investment capital but not later gains', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000, 'type' => App\Enums\AccountClass::Investment]);
    app(App\Services\AccountValuationService::class)->setOpeningBalance($account, 100000, $this->user->today()->subDays(30));
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 150000]);
    linkGoal($goal, $account);

    $before = $this->calculator->savedAmount($goal);
    $account->update(['balance' => 120000]);

    expect($before)->toBe(100000)
        ->and($this->calculator->savedAmount($goal->refresh()))->toBe(100000);
});

it('ignores historical one-off spends once a goal has become a reserve', function () {
    $account = goalAccount($this->user, 50000);
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 100000]);
    linkGoal($goal, $account);
    GoalSpend::factory()->for($goal)->for($account)->create(['amount' => 50000]);

    $asOneOff = $this->calculator->savedAmount($goal);
    $goal->update(['kind' => App\Enums\GoalKind::Reserve, 'target_date' => null]);
    $asReserve = $this->calculator->calculate($goal->refresh());

    expect($asOneOff)->toBe(100000)
        ->and($asReserve->savedAmount)->toBe(50000)
        ->and($asReserve->health)->toBe(GoalHealth::Depleted);
});

it('measures pace over exactly 90 days, today included', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $account = goalAccount($this->user, 0);
    linkGoal($goal, $account);
    inflow($this->user, $account, 30000, 0);
    inflow($this->user, $account, 30000, 89);
    inflow($this->user, $account, 90000, 90);

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(20000);
});

it('subtracts debits by magnitude whatever sign they are stored with in pace', function () {
    $goal = Goal::factory()->for($this->user)->create();
    $account = goalAccount($this->user, 0);
    linkGoal($goal, $account);
    inflow($this->user, $account, 30000);
    foreach ([-6000, 3000] as $amount) {
        Transaction::factory()->for($this->user)->for($account)->debit()->create([
            'amount' => $amount,
            'post_date' => $this->user->today()->subDays(5),
        ]);
    }

    expect($this->calculator->calculate($goal)->pacePerMonth)->toBe(7000);
});

function progressFixture(User $user, int $goalCount): Illuminate\Support\Collection
{
    $shared = goalAccount($user, 500000);
    $goals = collect();

    for ($i = 0; $i < $goalCount; $i++) {
        $goal = Goal::factory()->for($user)->create([
            'target_amount' => 100000,
            'target_date' => $user->today()->addDays(120),
        ]);
        $own = goalAccount($user, 40000 + $i);
        linkGoal($goal, $own, 30000);
        linkGoal($goal, $shared, 1000);
        inflow($user, $own, 6000 + $i);
        GoalSpend::factory()->for($goal)->for($own)->create(['amount' => 500]);
        $goals->push($goal);
    }

    return $goals;
}

function queriesFor(Closure $work): int
{
    $connection = Illuminate\Support\Facades\DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $work();
    $count = count($connection->getQueryLog());
    $connection->disableQueryLog();

    return $count;
}

it('computes progress for many goals with a query count that does not grow with the goals', function () {
    $today = $this->user->today();
    $few = progressFixture($this->user, 2);
    $fewQueries = queriesFor(fn () => $this->calculator->calculateMany($few, $today));

    $many = $few->merge(progressFixture($this->user, 8))->each->refresh();
    $manyQueries = queriesFor(fn () => $this->calculator->calculateMany($many, $today));

    expect($manyQueries)->toBe($fewQueries)
        ->and($fewQueries)->toBeLessThanOrEqual(8);
});

it('gives the same progress through calculateMany as through calculate', function () {
    $today = $this->user->today();
    $goals = progressFixture($this->user, 3);

    $bulk = $this->calculator->calculateMany($goals, $today);

    foreach ($goals as $goal) {
        expect($bulk[$goal->id])->toEqual($this->calculator->calculate($goal, $today));
    }

    expect($this->calculator->calculateMany(collect(), $today))->toBe([]);
});

it('computes backings from supplied balances without querying', function () {
    $earmarks = [1 => 1500, 2 => null, 3 => 400];
    $balances = [1 => 1000, 2 => 1000, 4 => 9999];
    $held = [1 => 500, 2 => 300];

    $backings = queriesFor(fn () => $this->calculator->backingsFor($earmarks, $balances, $held));
    $result = $this->calculator->backingsFor($earmarks, $balances, $held);

    expect($backings)->toBe(0)
        ->and($result)->toBe([1 => 750, 2 => 700])
        ->and($this->calculator->backingsFor([1 => 100], [1 => -50]))->toBe([1 => 0]);
});

it('loads earmarks and other goals holds once for per-day backings', function () {
    $account = goalAccount($this->user, 1000);
    $mine = Goal::factory()->for($this->user)->create();
    $other = Goal::factory()->for($this->user)->create();
    linkGoal($mine, $account, null);
    linkGoal($other, $account, 300);

    expect($this->calculator->earmarksOf($mine))->toBe([$account->id => null])
        ->and($this->calculator->heldByOthersOf($mine))->toBe([$account->id => 300])
        ->and($this->calculator->backingsFor($this->calculator->earmarksOf($mine), [$account->id => 1000], $this->calculator->heldByOthersOf($mine)))->toBe([$account->id => 700]);
});
