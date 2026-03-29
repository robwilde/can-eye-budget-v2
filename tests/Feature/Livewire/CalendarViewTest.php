<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Livewire\CalendarView;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
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
    $expectedLabel = now()->startOfMonth()->subMonth()->format('F Y');

    expect($data['monthLabel'])->toBe($expectedLabel)
        ->and($data['isCurrentMonth'])->toBeFalse();
});

test('next month navigation works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('nextMonth');

    $data = $component->get('calendarData');
    $expectedLabel = now()->startOfMonth()->addMonth()->format('F Y');

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

test('calendar refreshes after transaction-saved event', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $dataBefore = $component->get('calendarData');
    $allBefore = collect($dataBefore['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    expect($allBefore)->toHaveCount(0);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2500,
        'post_date' => now()->startOfMonth()->addDays(1),
    ]);

    $component->dispatch('transaction-saved');

    $dataAfter = $component->get('calendarData');
    $allAfter = collect($dataAfter['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    expect($allAfter)->toHaveCount(1);
});

test('transaction data includes id and source fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->manual()->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => now()->startOfMonth()->addDays(2),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn)
        ->toHaveKey('id', $transaction->id)
        ->toHaveKey('source', 'manual');
});

test('transaction data includes source for basiq transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->fromBasiq()->debit()->create([
        'account_id' => $account->id,
        'amount' => -5000,
        'post_date' => now()->startOfMonth()->addDays(4),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn['source'])->toBe('basiq');
});

test('transaction data includes isTransfer flag for transfer transactions', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();

    $debit = Transaction::factory()->for($user)->create([
        'account_id' => $fromAccount->id,
        'direction' => TransactionDirection::Debit,
        'post_date' => now()->startOfMonth()->addDays(3),
    ]);

    $credit = Transaction::factory()->for($user)->create([
        'account_id' => $toAccount->id,
        'direction' => TransactionDirection::Credit,
        'post_date' => now()->startOfMonth()->addDays(3),
        'transfer_pair_id' => $debit->id,
    ]);

    $debit->update(['transfer_pair_id' => $credit->id]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txns = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    $transferTxn = $txns->firstWhere('id', $debit->id);
    expect($transferTxn['isTransfer'])->toBeTrue();
});

test('non-transfer transaction has isTransfer false', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    Transaction::factory()->for($user)->manual()->create([
        'account_id' => $account->id,
        'post_date' => now()->startOfMonth()->addDays(3),
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    $data = $component->get('calendarData');
    $txn = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->first();

    expect($txn['isTransfer'])->toBeFalse();
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

// ── Planned Transactions ─────────────────────────────────────────

test('planned transaction occurrences appear on correct dates', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'category_id' => $category->id,
        'amount' => 150000,
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->startOfMonth()->addDays(14),
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $allTxns = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions']);

    $plannedTxns = $allTxns->where('type', 'planned');

    expect($plannedTxns)->toHaveCount(1)
        ->and($plannedTxns->first()['category'])->toBe('Rent')
        ->and($plannedTxns->first()['amount'])->toBe(150000)
        ->and($plannedTxns->first()['direction'])->toBe('debit')
        ->and($plannedTxns->first()['id'])->toBeNull()
        ->and($plannedTxns->first()['planned_transaction_id'])->toBe($planned->id);
});

test('weekly planned transaction shows on multiple weeks', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->weekly()->create([
        'start_date' => now()->startOfMonth(),
        'amount' => 5000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned->count())->toBeGreaterThanOrEqual(4);
});

test('monthly planned transaction shows once in current month days', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'start_date' => now()->startOfMonth()->addDays(9),
        'amount' => 10000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->filter(fn (array $day) => $day['isCurrentMonth'])
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned)->toHaveCount(1);
});

test('planned entries have type planned and actual entries have type actual', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(4);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => $date,
        'amount' => 5000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $day = collect($data['weeks'])->flatten(1)
        ->firstWhere('fullDate', $date->format('Y-m-d'));

    $actual = collect($day['transactions'])->where('type', 'actual');
    $planned = collect($day['transactions'])->where('type', 'planned');

    expect($actual)->toHaveCount(1)
        ->and($planned)->toHaveCount(1);
});

test('planned transaction respects until_date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->weekly()->create([
        'start_date' => now()->startOfMonth(),
        'until_date' => now()->startOfMonth()->addDays(7),
        'amount' => 2000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned->count())->toBeLessThanOrEqual(2);
});

test('inactive planned transactions are not shown', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->inactive()->create([
        'start_date' => now()->startOfMonth()->addDays(4),
        'amount' => 5000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned)->toBeEmpty();
});

test('actual and planned transactions on the same day both appear', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => $date,
        'amount' => 7000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $day = collect($data['weeks'])->flatten(1)
        ->firstWhere('fullDate', $date->format('Y-m-d'));

    expect($day['transactions'])->toHaveCount(2);

    $types = collect($day['transactions'])->pluck('type')->sort()->values()->all();
    expect($types)->toBe(['actual', 'planned']);
});

test('planned transactions starting after grid end are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => now()->startOfMonth()->addMonths(2),
        'amount' => 9999,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned)->toBeEmpty();
});

test('planned transactions with until_date before grid start are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->weekly()->create([
        'start_date' => now()->subMonths(6),
        'until_date' => now()->subMonths(3),
        'amount' => 5000,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned)->toBeEmpty();
});

test('only current user planned transactions are shown', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => now()->startOfMonth()->addDays(4),
    ]);

    PlannedTransaction::factory()->for($otherUser)->for($otherAccount)->noRepeat()->create([
        'start_date' => now()->startOfMonth()->addDays(4),
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $planned = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->where('type', 'planned');

    expect($planned)->toHaveCount(1);
});

// ── Reconciliation Status ────────────────────────────────────────

test('planned entry has reconciled status when linked transaction exists near occurrence date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('reconciled')
        ->and($plannedEntry['linked_transaction_id'])->not->toBeNull();
});

test('planned entry has unreconciled status when no matching transactions exist', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => now()->startOfMonth()->addDays(9),
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('unreconciled')
        ->and($plannedEntry['linked_transaction_id'])->toBeNull();
});

test('planned entry has suggested status when unlinked match exists', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 15000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 15000,
        'post_date' => $date,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('suggested');
});

test('suggestion status respects amount tolerance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 12000,
        'post_date' => $date,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('unreconciled');
});

test('suggestion status respects date tolerance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => 10000,
        'post_date' => $date->addDays(5),
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('unreconciled');
});

test('suggestion status requires same account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => 10000,
        'post_date' => $date,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('unreconciled');
});

test('suggestion status requires same direction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'amount' => 10000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 10000,
        'post_date' => $date,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['reconciliation_status'])->toBe('unreconciled');
});

test('planned entry includes occurrence_date field', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => $date,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $plannedEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'planned');

    expect($plannedEntry['occurrence_date'])->toBe($date->format('Y-m-d'));
});

test('actual transaction includes planned_transaction_id when linked', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = now()->startOfMonth()->addDays(9);

    $planned = PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => $date,
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'post_date' => $date,
        'planned_transaction_id' => $planned->id,
    ]);

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    $data = $component->get('calendarData');
    $actualEntry = collect($data['weeks'])->flatten(1)
        ->flatMap(fn (array $day) => $day['transactions'])
        ->firstWhere('type', 'actual');

    expect($actualEntry['planned_transaction_id'])->toBe($planned->id);
});
