<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionIngestor;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->ingestor = app(TransactionIngestor::class);
});

function fanoutPlan(User $user, Account $account, ?Category $category, int $amount, bool $active = true): PlannedTransaction
{
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $category?->id,
        'amount' => $amount,
        'direction' => TransactionDirection::Debit,
        'description' => 'Afterpay - '.fake()->company(),
        'start_date' => '2026-08-07',
        'until_date' => '2026-09-18',
        'frequency' => RecurrenceFrequency::Every2Weeks,
        'is_active' => $active,
    ]);

    BnplOrder::factory()->autoApproved()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'planned_transaction_id' => $plan->id,
        'instalment_amount' => $amount,
        'first_due_date' => '2026-08-07',
        'last_due_date' => '2026-09-18',
    ]);

    return $plan;
}

function fanoutDebit(Account $account, int $amount, string $postDate, string $description = 'VISA -Afterpay                 afterpay.com AU  145377 #8357'): Transaction
{
    return Transaction::factory()->for($account->user)->for($account)->debit()->fromRedbark()->make([
        'amount' => -$amount,
        'description' => $description,
        'post_date' => $postDate,
        'status' => TransactionStatus::Posted,
        'planned_transaction_id' => null,
    ]);
}

test('three instalments due on one day are split out of one combined debit', function () {
    $categories = Category::factory()->count(3)->create();
    $plans = $categories->map(fn (Category $category): PlannedTransaction => fanoutPlan($this->user, $this->account, $category, 1861));

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 5583, '2026-09-04'));

    $children = $debit->children()->get();

    expect($children)->toHaveCount(3)
        ->and($children->pluck('amount')->all())->toBe([-1861, -1861, -1861])
        ->and($children->pluck('planned_transaction_id')->sort()->values()->all())->toBe($plans->pluck('id')->sort()->values()->all())
        ->and($children->pluck('category_source')->unique()->all())->toBe([CategorySource::Manual])
        ->and($children->pluck('csv_hash')->filter()->all())->toBe([])
        ->and($children->pluck('parent_transaction_id')->unique()->all())->toBe([$debit->id])
        ->and($children->every(fn (Transaction $child): bool => $child->category_id === $child->plannedTransaction->category_id))->toBeTrue()
        ->and($children->first()->notes)->toContain('Afterpay debit split across 3 plans')
        ->and(Transaction::withTrashed()->find($debit->id)->trashed())->toBeTrue()
        ->and(Transaction::withTrashed()->find($debit->id)->folded_into_transaction_id)->toBe($children->first()->id)
        ->and(Transaction::query()->current()->where('user_id', $this->user->id)->pluck('id')->sort()->values()->all())
        ->toBe($children->pluck('id')->sort()->values()->all())
        ->and((int) Transaction::query()->current()->where('user_id', $this->user->id)->sum('amount'))->toBe(-5583);

    $day = app(DayActivityLoader::class)->load(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'), $this->user->id)['2026-09-04'];

    expect($day->postedCents)->toBe(5583)
        ->and($day->plannedCents)->toBe(0)
        ->and(collect($day->pips)->where('kind', 'plan')->count())->toBe(0);
});

test('the final instalments carry the extra cent on the last child', function () {
    foreach (range(1, 3) as $ignored) {
        fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    }

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 5584, '2026-09-18'));

    $amounts = $debit->children()->orderBy('id')->pluck('amount')->all();

    expect($amounts)->toBe([-1861, -1861, -1862])
        ->and(array_sum($amounts))->toBe(-5584);
});

test('a debit matching no subset is left alone', function () {
    foreach (range(1, 3) as $ignored) {
        fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    }

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 4000, '2026-09-04'));

    expect($debit->children()->count())->toBe(0)
        ->and($debit->fresh()->planned_transaction_id)->toBeNull()
        ->and(Transaction::query()->current()->count())->toBe(1);
});

test('two tolerant subsets with no exact one are left alone', function () {
    Log::spy();

    foreach ([1000, 1001, 2000] as $amount) {
        fanoutPlan($this->user, $this->account, Category::factory()->create(), $amount);
    }

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 3002, '2026-09-18'));

    expect($debit->children()->count())->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => $message === 'BNPL fan-out ambiguous')->once();
});

test('more than eight candidate instalments are left alone', function () {
    foreach (range(1, 9) as $ignored) {
        fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    }

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 16749, '2026-09-18'));

    expect($debit->children()->count())->toBe(0)
        ->and($debit->fresh()->planned_transaction_id)->toBeNull();
});

test('an occurrence already reconciled by another posting is not offered', function () {
    $plans = collect(range(1, 3))->map(fn (): PlannedTransaction => fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861));

    Transaction::factory()->for($this->user)->for($this->account)->debit()->create([
        'amount' => -1861,
        'post_date' => '2026-09-18',
        'planned_transaction_id' => $plans[0]->id,
    ]);

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 3722, '2026-09-18'));

    expect($debit->children()->pluck('planned_transaction_id')->sort()->values()->all())
        ->toBe([$plans[1]->id, $plans[2]->id]);
});

test('a non-BNPL narration never fans out', function () {
    foreach (range(1, 3) as $ignored) {
        fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    }

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 5583, '2026-09-04', 'WOOLWORTHS 1234'));

    expect($debit->children()->count())->toBe(0);
});

test("a rejected order's plan is not a candidate", function () {
    $first = fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    $second = fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);
    fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861, active: false);

    $untouched = $this->ingestor->ingest(fanoutDebit($this->account, 5583, '2026-09-18'));

    expect($untouched->children()->count())->toBe(0);

    $split = $this->ingestor->ingest(fanoutDebit($this->account, 3722, '2026-09-18'));

    expect($split->children()->pluck('planned_transaction_id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);
});

test('a single instalment still reconciles by tolerance without fanning out', function () {
    $plan = fanoutPlan($this->user, $this->account, Category::factory()->create(), 1861);

    $debit = $this->ingestor->ingest(fanoutDebit($this->account, 1861, '2026-09-04'));

    expect($debit->planned_transaction_id)->toBe($plan->id)
        ->and($debit->children()->count())->toBe(0);
});
