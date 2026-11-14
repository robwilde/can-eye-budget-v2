<?php

declare(strict_types=1);

use App\Contracts\InsightGenerator;
use App\Enums\InsightPriority;
use App\Enums\InsightStatus;
use App\Enums\InsightType;
use App\Enums\RecurrenceFrequency;
use App\Jobs\GenerateInsightsJob;
use App\Models\Account;
use App\Models\AccountValuation;
use App\Models\Category;
use App\Models\Insight;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InsightGenerators\CashFlowWarningGenerator;
use App\Services\InsightGenerators\SpendingAnomalyGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['budget.insights_enabled' => true]);
    $this->travelTo('2026-11-15 12:00:00');
});

function seedGroceryHistory(User $user, int $baselineCents, int $currentCents): Category
{
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $account = Account::factory()->for($user)->create(['balance' => 100000]);

    foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => $baselineCents,
            'post_date' => $date,
        ]);
    }

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => $currentCents,
        'post_date' => '2026-11-05',
    ]);

    return $category;
}

function anomalies(User $user): Illuminate\Database\Eloquent\Collection
{
    return Insight::query()->where('user_id', $user->id)->where('type', InsightType::SpendingAnomaly)->get();
}

it('creates a high priority spending anomaly at 50 percent above baseline', function () {
    $user = User::factory()->create();
    $category = seedGroceryHistory($user, 40000, 30000);

    GenerateInsightsJob::dispatchSync($user);

    $insight = anomalies($user)->sole();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->status)->toBe(InsightStatus::Active)
        ->and($insight->dedup_key)->toBe("spending_anomaly:{$category->id}:2026-11")
        ->and($insight->figures['projected_cents'])->toBe(60000)
        ->and($insight->figures['baseline_cents'])->toBe(40000);
});

it('skips spending anomalies in the first week of the month', function () {
    $this->travelTo('2026-11-06 12:00:00');
    $user = User::factory()->create();
    seedGroceryHistory($user, 40000, 30000);

    GenerateInsightsJob::dispatchSync($user);

    expect(anomalies($user))->toHaveCount(0);
});

it('raises a cash-flow warning below 500 and makes it high below zero', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 80000]);
    $user->update(['primary_account_id' => $account->id]);
    PlannedTransaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'amount' => 50000,
        'start_date' => '2026-11-20',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    GenerateInsightsJob::dispatchSync($user->fresh());

    $warning = Insight::query()->where('user_id', $user->id)->where('type', InsightType::CashFlowWarning)->sole();

    expect($warning->priority)->toBe(InsightPriority::Medium)
        ->and($warning->figures['lowest_balance_cents'])->toBe(30000);

    $account->update(['balance' => 40000]);
    GenerateInsightsJob::dispatchSync($user->fresh());

    $warning->refresh();

    expect($warning->priority)->toBe(InsightPriority::High)
        ->and($warning->status)->toBe(InsightStatus::Active)
        ->and($warning->figures['lowest_balance_cents'])->toBe(-10000);
});

it('ignores tracked to tracked transfers when projecting category spend', function () {
    $user = User::factory()->create();
    $category = seedGroceryHistory($user, 40000, 30000);
    $first = Account::factory()->for($user)->create();
    $second = Account::factory()->for($user)->create();

    foreach (['2026-08-12', '2026-09-12', '2026-10-12', '2026-11-12'] as $date) {
        $out = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $first->id,
            'category_id' => $category->id,
            'amount' => 500000,
            'post_date' => $date,
        ]);
        $in = Transaction::factory()->credit()->create([
            'user_id' => $user->id,
            'account_id' => $second->id,
            'category_id' => $category->id,
            'amount' => 500000,
            'post_date' => $date,
            'transfer_pair_id' => $out->id,
        ]);
        $out->update(['transfer_pair_id' => $in->id]);
    }

    GenerateInsightsJob::dispatchSync($user);

    $insight = anomalies($user)->sole();

    expect($insight->figures['baseline_cents'])->toBe(40000)
        ->and($insight->figures['projected_cents'])->toBe(60000);
});

it('keeps other insight types running when one generator fails', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    seedGroceryHistory($user, 40000, 30000);
    $cashFlow = Insight::factory()->for($user)->create(['type' => InsightType::CashFlowWarning, 'dedup_key' => 'cash_flow_warning']);
    $idle = Insight::factory()->for($user)->create(['type' => InsightType::IdleCash, 'dedup_key' => 'idle_cash:0']);
    app()->bind(CashFlowWarningGenerator::class, fn (): InsightGenerator => new class implements InsightGenerator
    {
        public function key(): string
        {
            return 'cash-flow-warning';
        }

        public function types(): array
        {
            return [InsightType::CashFlowWarning];
        }

        public function generate(User $user): array
        {
            throw new RuntimeException('projection unavailable');
        }
    });

    GenerateInsightsJob::dispatchSync($user);

    expect(anomalies($user))->toHaveCount(1)
        ->and($cashFlow->fresh()->status)->toBe(InsightStatus::Active)
        ->and($idle->fresh()->status)->toBe(InsightStatus::Expired);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'projection unavailable');
});

it('keeps later generators running when an earlier one cannot be resolved', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 900000, 'created_at' => '2026-01-01 00:00:00']);
    $existing = Insight::factory()->for($user)->create(['type' => InsightType::SpendingAnomaly, 'dedup_key' => 'spending_anomaly:0:2026-11']);
    app()->bind(SpendingAnomalyGenerator::class, fn () => throw new RuntimeException('constructor failed'));

    GenerateInsightsJob::dispatchSync($user);

    expect(Insight::query()->where('user_id', $user->id)->where('type', InsightType::IdleCash)->count())->toBe(1)
        ->and($existing->fresh()->status)->toBe(InsightStatus::Active);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'constructor failed');
});

it('generates insights only for the job user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    seedGroceryHistory($userA, 40000, 30000);
    seedGroceryHistory($userB, 40000, 30000);

    GenerateInsightsJob::dispatchSync($userA);

    expect(anomalies($userA))->toHaveCount(1)
        ->and(Insight::query()->where('user_id', $userB->id)->count())->toBe(0);
});

it('flags a savings rate change of 10 points as high priority', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);

    foreach ([['2026-09-05', 100000, 90000], ['2026-10-05', 100000, 70000]] as [$date, $income, $spend]) {
        Transaction::factory()->credit()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => $income, 'post_date' => $date]);
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => $spend, 'post_date' => $date]);
    }

    GenerateInsightsJob::dispatchSync($user);

    $insight = Insight::query()->where('user_id', $user->id)->where('type', InsightType::SavingsRateChange)->sole();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->dedup_key)->toBe('savings_rate_change:2026-10')
        ->and($insight->figures['change_points'])->toEqual(20)
        ->and($insight->comparison)->toBe(['month' => '2026-10', 'severity' => 'high', 'rate_tenths' => 300, 'previous_rate_tenths' => 100]);
});

it('reports the two largest idle cash accounts only', function () {
    $user = User::factory()->create();
    $aged = ['created_at' => '2026-01-01 00:00:00'];
    $largest = Account::factory()->for($user)->create(['balance' => 900000, ...$aged]);
    $second = Account::factory()->for($user)->savings()->create(['balance' => 800000, ...$aged]);
    Account::factory()->for($user)->create(['balance' => 600000, ...$aged]);
    Account::factory()->for($user)->create(['balance' => 400000, ...$aged]);
    Account::factory()->for($user)->create(['balance' => 990000]);
    $active = Account::factory()->for($user)->create(['balance' => 950000, ...$aged]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $active->id, 'post_date' => '2026-11-01']);

    GenerateInsightsJob::dispatchSync($user);

    expect(Insight::query()->where('user_id', $user->id)->where('type', InsightType::IdleCash)->pluck('dedup_key')->all())
        ->toEqualCanonicalizing(["idle_cash:{$largest->id}", "idle_cash:{$second->id}"]);
});

it('sums the size of debits rather than their signed amounts for the savings rate', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);

    foreach ([['2026-09-05', 100000, 90000], ['2026-10-05', 100000, -70000]] as [$date, $income, $spend]) {
        Transaction::factory()->credit()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => $income, 'post_date' => $date]);
        Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => $spend, 'post_date' => $date]);
    }

    GenerateInsightsJob::dispatchSync($user);

    $insight = Insight::query()->where('user_id', $user->id)->where('type', InsightType::SavingsRateChange)->sole();

    expect($insight->comparison)->toBe(['month' => '2026-10', 'severity' => 'high', 'rate_tenths' => 300, 'previous_rate_tenths' => 100]);
});

it('resurfaces a savings rate change when it crosses the high threshold', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    Transaction::factory()->credit()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 10000, 'post_date' => '2026-09-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 7996, 'post_date' => '2026-09-05']);
    Transaction::factory()->credit()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 10000, 'post_date' => '2026-10-05']);
    $spend = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => 7002, 'post_date' => '2026-10-05']);

    GenerateInsightsJob::dispatchSync($user);

    $insight = Insight::query()->where('user_id', $user->id)->where('type', InsightType::SavingsRateChange)->sole();

    expect($insight->priority)->toBe(InsightPriority::Medium)
        ->and($insight->figures['change_points'])->toEqual(9.9);

    $insight->update(['status' => InsightStatus::Acknowledged]);
    $spend->update(['amount' => 7000]);

    GenerateInsightsJob::dispatchSync($user);

    $insight->refresh();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->status)->toBe(InsightStatus::Active)
        ->and($insight->figures['change_points'])->toEqual(10);
});

it('records a spending anomaly priority change when crossing 50 percent', function () {
    $user = User::factory()->create();
    seedGroceryHistory($user, 40000, 29800);

    GenerateInsightsJob::dispatchSync($user);

    expect(anomalies($user)->sole()->priority)->toBe(InsightPriority::Medium);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::query()->where('user_id', $user->id)->value('id'), 'category_id' => Category::query()->where('user_id', $user->id)->value('id'), 'amount' => 200, 'post_date' => '2026-11-06']);
    anomalies($user)->sole()->update(['status' => InsightStatus::Acknowledged]);

    GenerateInsightsJob::dispatchSync($user);

    $insight = anomalies($user)->sole();

    expect($insight->priority)->toBe(InsightPriority::High)
        ->and($insight->status)->toBe(InsightStatus::Active);
});

it('records the outcome of a run by its run id', function () {
    $user = User::factory()->create();

    GenerateInsightsJob::dispatchSync($user, 'run-1');
    GenerateInsightsJob::dispatchSync($user);

    expect(Cache::get(GenerateInsightsJob::runKey('run-1')))->toBe('done')
        ->and(Cache::get(GenerateInsightsJob::runKey('run-2')))->toBeNull();

    (new GenerateInsightsJob($user, 'run-3'))->failed(new RuntimeException('boom'));

    expect(Cache::get(GenerateInsightsJob::runKey('run-3')))->toBe('failed');
});

it('marks a run failed rather than done when it throws', function () {
    Exceptions::fake();
    $user = User::factory()->create();
    Schema::drop('insights');

    expect(fn () => GenerateInsightsJob::dispatchSync($user, 'run-throws'))->toThrow(QueryException::class)
        ->and(Cache::get(GenerateInsightsJob::runKey('run-throws')))->toBe('failed');
});

it('does not report idle cash for an account with a superseded recent transaction only', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 900000, 'created_at' => '2026-01-01 00:00:00']);
    $other = Account::factory()->for($user)->create(['balance' => 1000, 'created_at' => '2026-01-01 00:00:00']);
    $original = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'post_date' => '2026-11-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $other->id, 'post_date' => '2026-01-10', 'parent_transaction_id' => $original->id]);

    GenerateInsightsJob::dispatchSync($user);

    expect(Insight::query()->where('type', InsightType::IdleCash)->pluck('dedup_key')->all())->toBe(["idle_cash:{$account->id}"]);
});

it('treats a balance-changing reconciliation as activity but not a confirmation', function () {
    $user = User::factory()->create();
    $changed = Account::factory()->for($user)->create(['balance' => 900000, 'created_at' => '2026-01-01 00:00:00']);
    $confirmed = Account::factory()->for($user)->create(['balance' => 800000, 'created_at' => '2026-01-01 00:00:00']);
    AccountValuation::factory()->create(['account_id' => $changed->id, 'valued_on' => '2026-11-10', 'balance' => 900000, 'difference' => 890000]);
    AccountValuation::factory()->create(['account_id' => $confirmed->id, 'valued_on' => '2026-11-10', 'balance' => 800000, 'difference' => 0]);

    GenerateInsightsJob::dispatchSync($user);

    expect(Insight::query()->where('type', InsightType::IdleCash)->pluck('dedup_key')->all())->toBe(["idle_cash:{$confirmed->id}"]);
});

it('records an idle cash insight for an account whose name fills the column', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => str_repeat('a', 255), 'balance' => 900000, 'created_at' => '2026-01-01 00:00:00']);

    GenerateInsightsJob::dispatchSync($user);

    $insight = Insight::query()->where('user_id', $user->id)->where('type', InsightType::IdleCash)->sole();

    expect(mb_strlen($insight->title))->toBeLessThanOrEqual(255);
});

it('names the currency of idle cash, spending anomalies and cash-flow warnings', function () {
    $user = User::factory()->create(['base_currency' => 'EUR']);
    Account::factory()->for($user)->create(['currency' => 'EUR', 'balance' => 900000, 'created_at' => '2026-01-01 00:00:00']);
    seedGroceryHistory($user, 40000, 30000);

    GenerateInsightsJob::dispatchSync($user);

    $idle = Insight::query()->where('user_id', $user->id)->where('type', InsightType::IdleCash)->first();
    $anomaly = anomalies($user)->sole();

    expect($idle->body)->toContain('EUR 9,000.00')->not->toContain('$')
        ->and($idle->figures['currency'])->toBe('EUR')
        ->and($anomaly->body)->toContain('EUR 600.00')->toContain('EUR 400.00')->not->toContain('$')
        ->and($anomaly->figures['currency'])->toBe('EUR');

    $primary = Account::factory()->for($user)->create(['currency' => 'EUR', 'balance' => 80000]);
    $user->update(['primary_account_id' => $primary->id]);
    PlannedTransaction::factory()->create(['user_id' => $user->id, 'account_id' => $primary->id, 'amount' => 50000, 'start_date' => '2026-11-20', 'frequency' => RecurrenceFrequency::EveryMonth]);

    GenerateInsightsJob::dispatchSync($user->fresh());

    $warning = Insight::query()->where('user_id', $user->id)->where('type', InsightType::CashFlowWarning)->sole();

    expect($warning->body)->toContain('EUR 300.00')->not->toContain('$')
        ->and($warning->figures['currency'])->toBe('EUR');
});

it('marks a run done even while insights are disabled', function () {
    config(['budget.insights_enabled' => false]);
    $user = User::factory()->create();

    GenerateInsightsJob::dispatchSync($user, 'run-off');

    expect(Cache::get(GenerateInsightsJob::runKey('run-off')))->toBe('done');
});

it('does nothing while insights are disabled', function () {
    config(['budget.insights_enabled' => false]);
    $user = User::factory()->create();
    seedGroceryHistory($user, 40000, 30000);

    GenerateInsightsJob::dispatchSync($user);

    expect(Insight::query()->count())->toBe(0);
});
