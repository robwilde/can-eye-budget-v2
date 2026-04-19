<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

// ── Factory & Basics ──────────────────────────────────────────────

test('factory creates a valid planned transaction', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned)->toBeInstanceOf(PlannedTransaction::class)
        ->and($planned->exists)->toBeTrue();
});

test('default factory creates monthly frequency', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth);
});

test('default factory creates active planned transaction', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->is_active)->toBeTrue();
});

// ── Relationships ─────────────────────────────────────────────────

test('planned transaction belongs to a user', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->user)->toBeInstanceOf(User::class);
});

test('planned transaction belongs to an account', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->account)->toBeInstanceOf(Account::class);
});

test('planned transaction belongs to a category', function () {
    $planned = PlannedTransaction::factory()->create([
        'category_id' => Category::factory(),
    ]);

    expect($planned->category)->toBeInstanceOf(Category::class);
});

test('category_id is nullable', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->category_id)->toBeNull();
});

test('planned transaction has many transactions', function () {
    $planned = PlannedTransaction::factory()->create();
    Transaction::factory()->count(3)
        ->for($planned->user)
        ->for($planned->account)
        ->create(['planned_transaction_id' => $planned->id]);

    expect($planned->transactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $transaction) => $transaction->toBeInstanceOf(Transaction::class));
});

test('transaction belongs to planned transaction', function () {
    $planned = PlannedTransaction::factory()->create();
    $transaction = Transaction::factory()
        ->for($planned->user)
        ->for($planned->account)
        ->create(['planned_transaction_id' => $planned->id]);

    expect($transaction->plannedTransaction)->toBeInstanceOf(PlannedTransaction::class)
        ->and($transaction->plannedTransaction->id)->toBe($planned->id);
});

test('user has many planned transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    PlannedTransaction::factory()->count(3)->for($user)->for($account)->create();

    expect($user->plannedTransactions)->toHaveCount(3)
        ->each(fn (Pest\Expectation $pt) => $pt->toBeInstanceOf(PlannedTransaction::class));
});

// ── Cascade Behavior ──────────────────────────────────────────────

test('deleting a user cascades to planned transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    PlannedTransaction::factory()->count(2)->for($user)->for($account)->create();

    $user->delete();

    expect(PlannedTransaction::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an account cascades to planned transactions', function () {
    $account = Account::factory()->create();
    PlannedTransaction::factory()->count(2)->for($account->user)->for($account)->create();

    $account->delete();

    expect(PlannedTransaction::where('account_id', $account->id)->count())->toBe(0);
});

test('deleting a category nullifies planned transaction category_id', function () {
    $category = Category::factory()->create();
    $planned = PlannedTransaction::factory()->create(['category_id' => $category->id]);

    $category->delete();

    expect($planned->fresh()->category_id)->toBeNull();
});

// ── Casts ─────────────────────────────────────────────────────────

test('direction is cast to TransactionDirection enum', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->direction)->toBeInstanceOf(TransactionDirection::class);
});

test('frequency is cast to RecurrenceFrequency enum', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->frequency)->toBeInstanceOf(RecurrenceFrequency::class);
});

test('amount is stored as integer cents', function () {
    $planned = PlannedTransaction::factory()->create(['amount' => 4599]);

    expect($planned->amount)->toBe(4599)
        ->and($planned->amount)->toBeInt();
});

test('start_date is cast to date', function () {
    $planned = PlannedTransaction::factory()->create(['start_date' => '2026-03-01']);

    expect($planned->start_date)
        ->toBeInstanceOf(CarbonImmutable::class)
        ->and($planned->start_date->toDateString())->toBe('2026-03-01');
});

test('until_date is cast to date', function () {
    $planned = PlannedTransaction::factory()->withEndDate()->create();

    expect($planned->until_date)->toBeInstanceOf(CarbonImmutable::class);
});

test('is_active is cast to boolean', function () {
    $planned = PlannedTransaction::factory()->create();

    expect($planned->is_active)->toBeBool();
});

// ── Factory States ────────────────────────────────────────────────

test('weekly state sets frequency to every week', function () {
    $planned = PlannedTransaction::factory()->weekly()->create();

    expect($planned->frequency)->toBe(RecurrenceFrequency::EveryWeek);
});

test('monthly state sets frequency to every month', function () {
    $planned = PlannedTransaction::factory()->monthly()->create();

    expect($planned->frequency)->toBe(RecurrenceFrequency::EveryMonth);
});

test('noRepeat state sets frequency to dont repeat', function () {
    $planned = PlannedTransaction::factory()->noRepeat()->create();

    expect($planned->frequency)->toBe(RecurrenceFrequency::DontRepeat);
});

test('withEndDate state sets until_date', function () {
    $planned = PlannedTransaction::factory()->withEndDate()->create();

    expect($planned->until_date)->not->toBeNull()
        ->and($planned->until_date)->toBeInstanceOf(CarbonImmutable::class);
});

test('inactive state sets is_active to false', function () {
    $planned = PlannedTransaction::factory()->inactive()->create();

    expect($planned->is_active)->toBeFalse();
});

// ── occurrencesBetween() ──────────────────────────────────────────

test('monthly occurrences returns correct dates for a 3-month window', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-01-15',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->toDateString())->toBe('2026-01-15')
        ->and($dates[1]->toDateString())->toBe('2026-02-15')
        ->and($dates[2]->toDateString())->toBe('2026-03-15');
});

test('weekly occurrences returns correct dates', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-03-02',
        'frequency' => RecurrenceFrequency::EveryWeek,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-22'),
    );

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->toDateString())->toBe('2026-03-02')
        ->and($dates[1]->toDateString())->toBe('2026-03-09')
        ->and($dates[2]->toDateString())->toBe('2026-03-16');
});

test('everyday occurrences returns correct dates', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-03-01',
        'frequency' => RecurrenceFrequency::Everyday,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-03'),
    );

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->toDateString())->toBe('2026-03-01')
        ->and($dates[1]->toDateString())->toBe('2026-03-02')
        ->and($dates[2]->toDateString())->toBe('2026-03-03');
});

test('dont repeat returns single date if in range', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-03-15',
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($dates)->toHaveCount(1)
        ->and($dates[0]->toDateString())->toBe('2026-03-15');
});

test('dont repeat returns empty if not in range', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-04-15',
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($dates)->toBeEmpty();
});

test('respects until_date boundary', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-01-15',
        'frequency' => RecurrenceFrequency::EveryMonth,
        'until_date' => '2026-03-01',
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($dates)->toHaveCount(2)
        ->and($dates[0]->toDateString())->toBe('2026-01-15')
        ->and($dates[1]->toDateString())->toBe('2026-02-15');
});

test('respects is_active flag', function () {
    $planned = PlannedTransaction::factory()->inactive()->create([
        'start_date' => '2026-03-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($dates)->toBeEmpty();
});

test('start date after range returns empty collection', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-06-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($dates)->toBeEmpty();
});

test('start date before range skips dates before range start', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2025-12-15',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-05-31'),
    );

    expect($dates)->toHaveCount(3)
        ->and($dates[0]->toDateString())->toBe('2026-03-15')
        ->and($dates[1]->toDateString())->toBe('2026-04-15')
        ->and($dates[2]->toDateString())->toBe('2026-05-15');
});

test('monthly on 31st handles short months', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-01-31',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-04-30'),
    );

    expect($dates)->toHaveCount(4)
        ->and($dates[0]->toDateString())->toBe('2026-01-31')
        ->and($dates[1]->toDateString())->toBe('2026-02-28')
        ->and($dates[2]->toDateString())->toBe('2026-03-28')
        ->and($dates[3]->toDateString())->toBe('2026-04-28');
});

test('occurrence on exact range boundary dates are included', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-03-15',
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $startBoundary = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-15'),
        CarbonImmutable::parse('2026-03-31'),
    );

    $endBoundary = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-15'),
    );

    expect($startBoundary)->toHaveCount(1)
        ->and($endBoundary)->toHaveCount(1);
});

test('every workday skips weekends in occurrences', function () {
    $planned = PlannedTransaction::factory()->create([
        'start_date' => '2026-03-26',
        'frequency' => RecurrenceFrequency::EveryWorkday,
    ]);

    $dates = $planned->occurrencesBetween(
        CarbonImmutable::parse('2026-03-26'),
        CarbonImmutable::parse('2026-04-01'),
    );

    $dateStrings = $dates->map(fn (CarbonImmutable $d) => $d->toDateString())->all();

    expect($dateStrings)->toBe([
        '2026-03-26',
        '2026-03-27',
        '2026-03-30',
        '2026-03-31',
        '2026-04-01',
    ]);
});

// ── scopeUpcoming() ───────────────────────────────────────────────

test('upcoming scope includes active plans with no until_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'start_date' => now()->subDays(7),
        'is_active' => true,
        'until_date' => null,
    ]);

    expect(PlannedTransaction::query()->upcoming()->count())->toBe(1);
});

test('upcoming scope includes active plans with future until_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'start_date' => now()->subDays(7),
        'is_active' => true,
        'until_date' => now()->addMonths(2),
    ]);

    expect(PlannedTransaction::query()->upcoming()->count())->toBe(1);
});

test('upcoming scope excludes inactive plans', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->inactive()->for($user)->for($account)->create([
        'start_date' => now()->subDays(3),
        'until_date' => null,
    ]);

    expect(PlannedTransaction::query()->upcoming()->count())->toBe(0);
});

test('upcoming scope excludes plans whose until_date has passed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'start_date' => now()->subMonths(2),
        'is_active' => true,
        'until_date' => now()->subDays(1),
    ]);

    expect(PlannedTransaction::query()->upcoming()->count())->toBe(0);
});

test('upcoming scope orders by start_date ascending', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $later = PlannedTransaction::factory()->for($user)->for($account)->create([
        'start_date' => now()->addDays(10),
    ]);
    $earlier = PlannedTransaction::factory()->for($user)->for($account)->create([
        'start_date' => now()->addDays(2),
    ]);

    $results = PlannedTransaction::query()->upcoming()->get();

    expect($results->pluck('id')->all())->toBe([$earlier->id, $later->id]);
});
