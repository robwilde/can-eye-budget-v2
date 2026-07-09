<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationMatcher;
use App\Support\Calendar\DayActivity;
use App\Support\Calendar\DayActivityLoader;
use Carbon\CarbonImmutable;

test('returns empty array when no activity in range', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $start = CarbonImmutable::create(2026, 6, 1);
    $end = CarbonImmutable::create(2026, 6, 30);

    $activity = (new DayActivityLoader)->load($start, $end, $user->id);

    expect($activity)->toBeEmpty();
});

test('groups posted transactions by ISO date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $dateA = CarbonImmutable::create(2026, 6, 5);
    $dateB = CarbonImmutable::create(2026, 6, 12);

    Transaction::factory()->for($user)->debit()->count(2)->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $dateA,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2500,
        'post_date' => $dateB,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toHaveKey($dateA->format('Y-m-d'))
        ->and($activity)->toHaveKey($dateB->format('Y-m-d'))
        ->and($activity[$dateA->format('Y-m-d')]->pips)->toHaveCount(2)
        ->and($activity[$dateB->format('Y-m-d')]->pips)->toHaveCount(1);
});

test('credit becomes inc pip and contributes to incomeCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $date = CarbonImmutable::create(2026, 6, 7);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips[0]->kind)->toBe('inc')
        ->and($day->incomeCents)->toBe(5000)
        ->and($day->postedCents)->toBe(0);
});

test('debit becomes out pip and contributes to postedCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $date = CarbonImmutable::create(2026, 6, 7);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips[0]->kind)->toBe('out')
        ->and($day->postedCents)->toBe(3000)
        ->and($day->incomeCents)->toBe(0);
});

test('planned occurrences become plan pips and contribute to plannedCents', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);

    $date = CarbonImmutable::create(2026, 6, 14);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 150000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('plan')
        ->and($day->pips[0]->name)->toBe('Rent')
        ->and($day->plannedCents)->toBe(150000);
});

test('pips on the same day are sorted by amount descending', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -8000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 4000,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    $amounts = array_map(fn ($pip) => $pip->amount, $day->pips);

    expect($amounts)->toBe([8000, 4000, 1000]);
});

test('range filtering excludes activity outside start/end', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -9999,
        'post_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -8888,
        'post_date' => CarbonImmutable::create(2026, 7, 1),
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toBeEmpty();
});

test('only loads transactions for the given user id', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => -9999,
        'post_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->amount)->toBe(1000);
});

test('excludes transfer-pair transactions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'direction' => TransactionDirection::Debit,
        'amount' => -1000,
        'post_date' => $date,
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'direction' => TransactionDirection::Credit,
        'amount' => 1000,
        'post_date' => $date,
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);

    expect($activity)->toBeEmpty();
});

test('inactive planned transactions are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    PlannedTransaction::factory()->for($user)->for($account)->inactive()->create([
        'start_date' => $date,
        'amount' => 5000,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->toBeEmpty();
});

test('actual and planned on same day appear together with correct tallies', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 8000,
        'post_date' => $date,
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $activity = (new DayActivityLoader)->load($date, $date, $user->id);
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(3)
        ->and($day->incomeCents)->toBe(8000)
        ->and($day->postedCents)->toBe(3000)
        ->and($day->plannedCents)->toBe(5000);
});

// ── reconciled-occurrence suppression ────────────────────────────

test('reconciled debit suppresses the planned pip and relabels the posted pip with the plan category', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent', 'icon' => 'home']);
    $date = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 77000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -77000,
        'post_date' => $date,
        'description' => 'Ext Tfr - NET#4789778169 Sekisui House',
        'planned_transaction_id' => $planned->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );
    $day = $activity[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('out')
        ->and($day->pips[0]->transactionId)->not->toBeNull()
        ->and($day->pips[0]->name)->toBe('Rent')
        ->and($day->pips[0]->icon)->toBe('home')
        ->and($day->pips[0]->matched)->toBeTrue()
        ->and($day->postedCents)->toBe(77000)
        ->and($day->plannedCents)->toBe(0);
});

test('an unmatched posted transaction is not flagged as matched', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 14);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -4200,
        'post_date' => $date,
        'planned_transaction_id' => null,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('out')
        ->and($day->pips[0]->matched)->toBeFalse();
});

test('reconciled income credit suppresses the planned income pip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Salary', 'icon' => 'banknotes']);
    $date = CarbonImmutable::create(2026, 6, 15);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 500000,
        'direction' => TransactionDirection::Credit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 500000,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('inc')
        ->and($day->pips[0]->name)->toBe('Salary')
        ->and($day->incomeCents)->toBe(500000)
        ->and($day->plannedCents)->toBe(0);
});

test('monthly recurring planned keeps unreconciled occurrences when one is reconciled', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $start = CarbonImmutable::create(2026, 6, 5);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'amount' => 30000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $start,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -30000,
        'post_date' => $start,
        'planned_transaction_id' => $planned->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 8, 31),
        $user->id,
    );

    expect($activity['2026-06-05']->pips)->toHaveCount(1)
        ->and($activity['2026-06-05']->pips[0]->kind)->toBe('out')
        ->and($activity['2026-06-05']->plannedCents)->toBe(0)
        ->and($activity['2026-07-05']->pips)->toHaveCount(1)
        ->and($activity['2026-07-05']->pips[0]->kind)->toBe('plan')
        ->and($activity['2026-07-05']->plannedCents)->toBe(30000)
        ->and($activity['2026-08-05']->pips[0]->kind)->toBe('plan');
});

test('reconciled posting outside the date tolerance does not suppress the planned pip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);
    $occurrence = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $occurrence,
    ]);

    $postDate = $occurrence->addDays(ReconciliationMatcher::DATE_TOLERANCE_DAYS + 1);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -20000,
        'post_date' => $postDate,
        'description' => 'Ext Tfr noisy bank text',
        'planned_transaction_id' => $planned->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity['2026-06-14']->pips)->toHaveCount(1)
        ->and($activity['2026-06-14']->pips[0]->kind)->toBe('plan')
        ->and($activity['2026-06-14']->pips[0]->name)->toBe('Rent')
        ->and($activity['2026-06-14']->plannedCents)->toBe(20000)
        ->and($activity[$postDate->format('Y-m-d')]->pips)->toHaveCount(1)
        ->and($activity[$postDate->format('Y-m-d')]->pips[0]->kind)->toBe('out')
        ->and($activity[$postDate->format('Y-m-d')]->pips[0]->name)->toBe('Rent');
});

test('reconciled posting exactly on the tolerance boundary suppresses the planned pip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent', 'icon' => 'home']);
    $occurrence = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 20000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $occurrence,
    ]);

    $postDate = $occurrence->addDays(ReconciliationMatcher::DATE_TOLERANCE_DAYS);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -20000,
        'post_date' => $postDate,
        'description' => 'Ext Tfr noisy bank text',
        'planned_transaction_id' => $planned->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity)->not->toHaveKey('2026-06-14')
        ->and($activity[$postDate->format('Y-m-d')]->pips)->toHaveCount(1)
        ->and($activity[$postDate->format('Y-m-d')]->pips[0]->kind)->toBe('out')
        ->and($activity[$postDate->format('Y-m-d')]->pips[0]->name)->toBe('Rent')
        ->and($activity[$postDate->format('Y-m-d')]->pips[0]->icon)->toBe('home');
});

test('two close occurrences with one reconciled posting suppress only the nearest occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $planned = PlannedTransaction::factory()->for($user)->for($account)->weekly()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => CarbonImmutable::create(2026, 6, 8),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -10000,
        'post_date' => CarbonImmutable::create(2026, 6, 9),
        'planned_transaction_id' => $planned->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 20),
        $user->id,
    );

    expect($activity)->not->toHaveKey('2026-06-08')
        ->and($activity['2026-06-09']->pips[0]->kind)->toBe('out')
        ->and($activity['2026-06-15']->pips)->toHaveCount(1)
        ->and($activity['2026-06-15']->pips[0]->kind)->toBe('plan')
        ->and($activity['2026-06-15']->plannedCents)->toBe(10000);
});

test('superseded child reconciled transaction suppresses the occurrence once', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 45000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $parent = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -45000,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);
    $child = $parent->createChild(['amount' => -45000]);

    expect(Transaction::query()->current()->where('planned_transaction_id', $planned->id)->pluck('id')->all())
        ->toBe([$child->id]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('out')
        ->and($day->pips[0]->transactionId)->toBe($child->id)
        ->and($day->postedCents)->toBe(45000)
        ->and($day->plannedCents)->toBe(0);
});

test('un-reconciled child shows both the posted and the planned pip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);
    $date = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 45000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $parent = Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -45000,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);
    $parent->createChild(['planned_transaction_id' => null]);

    expect(Transaction::query()->current()->where('user_id', $user->id)->pluck('planned_transaction_id')->all())
        ->toBe([null]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(2)
        ->and(collect($day->pips)->pluck('kind')->all())->toContain('plan')
        ->and(collect($day->pips)->pluck('kind')->all())->toContain('out')
        ->and($day->plannedCents)->toBe(45000)
        ->and($day->postedCents)->toBe(45000);
});

test('planned reconciled then deactivated leaves only the posted pip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent', 'icon' => 'home']);
    $date = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 45000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -45000,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);

    $planned->update(['is_active' => false]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('out')
        ->and($day->pips[0]->name)->toBe('Rent')
        ->and($day->plannedCents)->toBe(0);
});

test('two plans on the same day suppress only the reconciled one', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $rent = Category::factory()->create(['name' => 'Rent']);
    $power = Category::factory()->create(['name' => 'Power']);
    $date = CarbonImmutable::create(2026, 6, 14);

    $reconciledPlan = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $rent->id,
        'amount' => 45000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $power->id,
        'amount' => 12000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -45000,
        'post_date' => $date,
        'planned_transaction_id' => $reconciledPlan->id,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    $planPips = collect($day->pips)->where('kind', 'plan')->values();

    expect($day->pips)->toHaveCount(2)
        ->and($planPips)->toHaveCount(1)
        ->and($planPips[0]->name)->toBe('Power')
        ->and($day->plannedCents)->toBe(12000)
        ->and($day->postedCents)->toBe(45000);
});

test('a posting linked to a different plan does not suppress an unrelated occurrence', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 14);

    $visiblePlan = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 30000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $otherPlan = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 9000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -9000,
        'post_date' => $date,
        'planned_transaction_id' => $otherPlan->id,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    $planPips = collect($day->pips)->where('kind', 'plan')->values();

    expect($day->pips)->toHaveCount(2)
        ->and($planPips)->toHaveCount(1)
        ->and($planPips[0]->plannedTransactionId)->toBe($visiblePlan->id)
        ->and($day->plannedCents)->toBe(30000)
        ->and($day->postedCents)->toBe(9000);
});

test('plannedCents excludes a suppressed occurrence but keeps unreconciled occurrences', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $reconciledDate = CarbonImmutable::create(2026, 6, 10);
    $freeDate = CarbonImmutable::create(2026, 6, 20);

    $reconciledPlan = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 60000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $reconciledDate,
    ]);
    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $freeDate,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -60000,
        'post_date' => $reconciledDate,
        'planned_transaction_id' => $reconciledPlan->id,
    ]);

    $activity = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    );

    expect($activity['2026-06-10']->plannedCents)->toBe(0)
        ->and($activity['2026-06-10']->postedCents)->toBe(60000)
        ->and($activity['2026-06-20']->plannedCents)->toBe(15000)
        ->and($activity['2026-06-20']->pips[0]->kind)->toBe('plan');
});

// ── Pip tooltip ───────────────────────────────────────────────────

test('categorised planned pip tooltip equals the plan description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Electricity']);
    $date = CarbonImmutable::create(2026, 6, 10);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'description' => 'AGL Bill - June',
        'amount' => 18000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('plan')
        ->and($day->pips[0]->name)->toBe('Electricity')
        ->and($day->pips[0]->tooltip)->toBe('AGL Bill - June');
});

test('uncategorised planned pip has null tooltip because name equals description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 10);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => null,
        'description' => 'Gym Membership',
        'amount' => 5000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->name)->toBe('Gym Membership')
        ->and($day->pips[0]->tooltip)->toBeNull();
});

test('reconciled posted pip tooltip equals the bank transaction description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);
    $date = CarbonImmutable::create(2026, 6, 14);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'category_id' => $category->id,
        'amount' => 77000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -77000,
        'post_date' => $date,
        'description' => 'Ext Tfr - NET#4789778169 Sekisui House',
        'planned_transaction_id' => $planned->id,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->kind)->toBe('out')
        ->and($day->pips[0]->name)->toBe('Rent')
        ->and($day->pips[0]->tooltip)->toBe('Ext Tfr - NET#4789778169 Sekisui House');
});

test('categorised posted pip tooltip equals the transaction description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);
    $date = CarbonImmutable::create(2026, 6, 20);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -8500,
        'post_date' => $date,
        'description' => 'WOOLWORTHS 4232 BRISBANE',
        'planned_transaction_id' => null,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->name)->toBe('Groceries')
        ->and($day->pips[0]->tooltip)->toBe('WOOLWORTHS 4232 BRISBANE');
});

test('uncategorised posted pip whose name equals its description has null tooltip', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::create(2026, 6, 22);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -3000,
        'post_date' => $date,
        'description' => 'PARKING FEE CBD',
        'planned_transaction_id' => null,
    ]);

    $day = (new DayActivityLoader)->load(
        CarbonImmutable::create(2026, 6, 1),
        CarbonImmutable::create(2026, 6, 30),
        $user->id,
    )[$date->format('Y-m-d')];

    expect($day->pips)->toHaveCount(1)
        ->and($day->pips[0]->name)->toBe('PARKING FEE CBD')
        ->and($day->pips[0]->tooltip)->toBeNull();
});

test('DayActivity::empty returns a zero-state instance', function () {
    $empty = DayActivity::empty();

    expect($empty->pips)->toBe([])
        ->and($empty->incomeCents)->toBe(0)
        ->and($empty->postedCents)->toBe(0)
        ->and($empty->plannedCents)->toBe(0);
});
