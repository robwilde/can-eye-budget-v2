<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Livewire\CalendarView;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->assertSuccessful();
});

test('defaults to current month', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');

    expect($data['monthLabel'])->toBe(now()->format('F Y'))
        ->and($data['isCurrentMonth'])->toBeTrue();
});

test('shows transactions for current month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -4250,
        'post_date' => now()->startOfMonth()->addDays(5),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $allTransactions = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    expect($allTransactions)->toHaveCount(1)
        ->and($allTransactions->first()['category'])->toBe('Groceries')
        ->and($allTransactions->first()['amount'])->toBe(-4250)
        ->and($allTransactions->first()['direction'])->toBe('debit');
});

test('only shows current user transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => now()->startOfMonth()->addDays(3),
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => -8000,
        'post_date' => now()->startOfMonth()->addDays(3),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $allTransactions = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    expect($allTransactions)->toHaveCount(1)
        ->and($allTransactions->first()['amount'])->toBe(-3000);
});

test('groups transactions by date correctly', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $dateA = now()->startOfMonth()->addDays(2);
    $dateB = now()->startOfMonth()->addDays(5);

    Transaction::factory()->for($user)->debit()->count(2)->create([
        'account_id' => $account->id,
        'amount' => -1000,
        'post_date' => $dateA,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2000,
        'post_date' => $dateB,
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $days = collect($data['weeks'])->flatten(1);

    $dayA = $days->firstWhere('fullDate', $dateA->format('Y-m-d'));
    $dayB = $days->firstWhere('fullDate', $dateB->format('Y-m-d'));

    expect($dayA['transactions'])->toHaveCount(2)
        ->and($dayB['transactions'])->toHaveCount(1);
});

test('previous month navigation works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('previousMonth');

    $data = $component->get('calendarData');
    $expectedLabel = now()->subMonth()->format('F Y');

    expect($data['monthLabel'])->toBe($expectedLabel)
        ->and($data['isCurrentMonth'])->toBeFalse();
});

test('next month navigation works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('nextMonth');

    $data = $component->get('calendarData');
    $expectedLabel = now()->addMonth()->format('F Y');

    expect($data['monthLabel'])->toBe($expectedLabel)
        ->and($data['isCurrentMonth'])->toBeFalse();
});

test('today button resets to current month', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('previousMonth')
        ->call('previousMonth')
        ->call('goToToday');

    $data = $component->get('calendarData');

    expect($data['monthLabel'])->toBe(now()->format('F Y'))
        ->and($data['isCurrentMonth'])->toBeTrue();
});

test('transactions include category names', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Transport']);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -1500,
        'post_date' => now()->startOfMonth()->addDays(1),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn['category'])->toBe('Transport');
});

test('uncategorised transactions show description as fallback', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'WOOLWORTHS 1234 SYDNEY',
        'amount' => -500,
        'post_date' => now()->startOfMonth()->addDays(1),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn['category'])->toBe('WOOLWORTHS 1234 SYDNEY');
});

test('empty state when no transactions', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->assertSee('No transactions');
});

test('credit transactions have credit direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'post_date' => now()->startOfMonth()->addDays(1),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn['direction'])->toBe('credit')
        ->and($txn['amount'])->toBe(5000);
});

test('overflow days from adjacent months are marked', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $allDays = collect($data['weeks'])->flatten(1);

    $overflowDays = $allDays->filter(fn (array $day) => ! $day['isCurrentMonth']);
    $currentMonthDays = $allDays->filter(fn (array $day) => $day['isCurrentMonth']);

    expect($overflowDays->isNotEmpty())->toBeTrue()
        ->and($currentMonthDays->isNotEmpty())->toBeTrue();
});

test('calendar weeks always have 7 days', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');

    foreach ($data['weeks'] as $week) {
        expect($week)->toHaveCount(7);
    }
});

test('today is marked in current month', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $todayCell = collect($data['weeks'])->flatten(1)
        ->first(fn (array $day) => $day['isToday']);

    expect($todayCell)->not->toBeNull()
        ->and($todayCell['date'])->toBe((int) now()->format('j'))
        ->and($todayCell['isCurrentMonth'])->toBeTrue();
});
