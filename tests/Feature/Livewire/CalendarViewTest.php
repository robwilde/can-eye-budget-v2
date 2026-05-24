<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\CalendarView;
use App\Livewire\Data\CalendarDayData;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
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

    $header = $component->get('headerLabel');

    expect($header['label'])->toBe(now()->format('F Y'))
        ->and($header['isCurrentMonth'])->toBeTrue();
});

test('renders a full month grid Monday through Sunday', function () {
    $user = User::factory()->create();

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    expect($days)->not->toBeEmpty()
        ->and(count($days) % 7)->toBe(0)
        ->and(count($days))->toBeIn([28, 35, 42])
        ->and($days[0]->isoWeekday)->toBe(UnitValue::MONDAY)
        ->and(end($days)->isoWeekday)->toBe(7);
});

test('marks leading and trailing days as out-of-month', function () {
    $user = User::factory()->create();

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $monthStart = CarbonImmutable::now()->startOfMonth();
    $outDays = collect($days)->filter(fn (CalendarDayData $d) => ! $d->isCurrentMonth);
    $inDays = collect($days)->filter(fn (CalendarDayData $d) => $d->isCurrentMonth);

    expect($inDays->isNotEmpty())->toBeTrue()
        ->and($inDays->every(fn (CalendarDayData $d) => str_starts_with($d->iso, $monthStart->format('Y-m'))))->toBeTrue();

    if ($monthStart->isoWeekday() !== UnitValue::MONDAY || CarbonImmutable::now()->endOfMonth()->isoWeekday() !== UnitValue::SUNDAY) {
        expect($outDays->isNotEmpty())->toBeTrue();
    }
});

test('today is marked when current month is in view', function () {
    $user = User::factory()->create();

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $todayDay = collect($days)->first(fn (CalendarDayData $d) => $d->isToday);

    expect($todayDay)->not->toBeNull()
        ->and($todayDay?->iso)->toBe(CarbonImmutable::today()->format('Y-m-d'));
});

test('shows transactions for current month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Groceries']);
    $date = CarbonImmutable::now()->startOfMonth()->addDays(5);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => $category->id,
        'amount' => -4250,
        'post_date' => $date,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell)->not->toBeNull()
        ->and($cell->pips)->toHaveCount(1)
        ->and($cell->pips[0]->name)->toBe('Groceries')
        ->and($cell->pips[0]->kind)->toBe('out');
});

test('only shows current user transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(3);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    Transaction::factory()->for($otherUser)->debit()->create([
        'account_id' => $otherAccount->id,
        'amount' => -8000,
        'post_date' => $date,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $allPips = collect($days)->flatMap(fn (CalendarDayData $d) => $d->pips);

    expect($allPips)->toHaveCount(1)
        ->and($allPips->first()->amount)->toBe(3000);
});

test('groups transactions by date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $dateA = CarbonImmutable::now()->startOfMonth()->addDays(2);
    $dateB = CarbonImmutable::now()->startOfMonth()->addDays(5);

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

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cellA = collect($days)->firstWhere('iso', $dateA->format('Y-m-d'));
    $cellB = collect($days)->firstWhere('iso', $dateB->format('Y-m-d'));

    expect($cellA->pips)->toHaveCount(2)
        ->and($cellB->pips)->toHaveCount(1);
});

test('computes per-day net of income minus outflow', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(7);

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

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell->incomeCents)->toBe(8000)
        ->and($cell->postedCents)->toBe(3000)
        ->and($cell->netCents)->toBe(5000);
});

test('selectDate updates selectedDay computed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(4);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 12000,
        'post_date' => $date,
    ]);

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('selectDate', $date->format('Y-m-d'));

    $selected = $component->get('selectedDay');

    expect($selected)->not->toBeNull()
        ->and($selected['iso'])->toBe($date->format('Y-m-d'))
        ->and($selected['pips'])->toHaveCount(1);
});

test('monthTotals only sums current-month days', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $monthStart = CarbonImmutable::now()->startOfMonth();

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 100000,
        'post_date' => $monthStart->addDays(3),
    ]);
    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -25000,
        'post_date' => $monthStart->addDays(8),
    ]);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -99999,
        'post_date' => $monthStart->subDay(),
    ]);

    $totals = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->get('monthTotals');

    expect($totals['income'])->toBe(100000)
        ->and($totals['spend'])->toBe(25000)
        ->and($totals['net'])->toBe(75000);
});

test('previous month navigation works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('previousMonth');

    $header = $component->get('headerLabel');
    $expectedLabel = CarbonImmutable::now()->startOfMonth()->subMonth()->format('F Y');

    expect($header['label'])->toBe($expectedLabel)
        ->and($header['isCurrentMonth'])->toBeFalse();
});

test('next month navigation works', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('nextMonth');

    $header = $component->get('headerLabel');
    $expectedLabel = CarbonImmutable::now()->startOfMonth()->addMonth()->format('F Y');

    expect($header['label'])->toBe($expectedLabel)
        ->and($header['isCurrentMonth'])->toBeFalse();
});

test('today button resets to current month', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('previousMonth')
        ->call('previousMonth')
        ->call('goToToday');

    $header = $component->get('headerLabel');

    expect($header['label'])->toBe(now()->format('F Y'))
        ->and($header['isCurrentMonth'])->toBeTrue()
        ->and($component->get('selectedDate'))->toBe(CarbonImmutable::today()->format('Y-m-d'));
});

test('uncategorised transaction falls back to description', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(1);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'WOOLWORTHS 1234 SYDNEY',
        'amount' => -500,
        'post_date' => $date,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell->pips[0]->name)->toBe('WOOLWORTHS 1234 SYDNEY');
});

test('credit transactions become inc pips', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(1);

    Transaction::factory()->for($user)->credit()->create([
        'account_id' => $account->id,
        'amount' => 5000,
        'post_date' => $date,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell->pips[0]->kind)->toBe('inc')
        ->and($cell->pips[0]->amount)->toBe(5000);
});

test('calendar refreshes after transaction-saved event', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class);

    /** @var CalendarView $instance */
    $instance = $component->instance();
    $before = collect($instance->days())->flatMap(fn (CalendarDayData $d) => $d->pips);
    expect($before)->toHaveCount(0);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -2500,
        'post_date' => CarbonImmutable::now()->startOfMonth()->addDays(1),
    ]);

    $component->dispatch('transaction-saved');

    $after = collect($instance->days())->flatMap(fn (CalendarDayData $d) => $d->pips);
    expect($after)->toHaveCount(1);
});

test('month nav resets selectedDate to start of new month', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->call('previousMonth');

    $expected = CarbonImmutable::now()->startOfMonth()->subMonth()->format('Y-m-d');

    expect($component->get('selectedDate'))->toBe($expected);
});

test('selectedDate defaults to today on mount', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(CalendarView::class);

    expect($component->get('selectedDate'))->toBe(CarbonImmutable::today()->format('Y-m-d'));
});

// ── Planned transactions ─────────────────────────────────────────

test('planned transaction occurrences appear as plan pips', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->create(['name' => 'Rent']);
    $date = CarbonImmutable::now()->startOfMonth()->addDays(14);

    PlannedTransaction::factory()->for($user)->for($account)->monthly()->create([
        'category_id' => $category->id,
        'amount' => 150000,
        'direction' => TransactionDirection::Debit,
        'start_date' => $date,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $planPips = collect($days)->flatMap(fn (CalendarDayData $d) => $d->pips)->where('kind', 'plan');

    expect($planPips)->toHaveCount(1)
        ->and($planPips->first()->name)->toBe('Rent')
        ->and($planPips->first()->amount)->toBe(150000);
});

test('inactive planned transactions are excluded', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->inactive()->create([
        'start_date' => CarbonImmutable::now()->startOfMonth()->addDays(4),
        'amount' => 5000,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $planPips = collect($days)->flatMap(fn (CalendarDayData $d) => $d->pips)->where('kind', 'plan');

    expect($planPips)->toBeEmpty();
});

test('actual and planned on same day both render', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(9);

    Transaction::factory()->for($user)->debit()->create([
        'account_id' => $account->id,
        'amount' => -3000,
        'post_date' => $date,
    ]);

    PlannedTransaction::factory()->for($user)->for($account)->noRepeat()->create([
        'start_date' => $date,
        'amount' => 7000,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell->pips)->toHaveCount(2);

    $kinds = collect($cell->pips)->pluck('kind')->sort()->values()->all();

    expect($kinds)->toBe(['out', 'plan']);
});

// ── Payday flagging ──────────────────────────────────────────────

test('next upcoming payday in current month is flagged isNextPayday', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 10));

    $payday = CarbonImmutable::create(2026, 6, 20);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $payday,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $paydayCell = collect($days)->firstWhere('iso', $payday->format('Y-m-d'));

    expect($paydayCell)->not->toBeNull()
        ->and($paydayCell->isCurrentMonth)->toBeTrue()
        ->and($paydayCell->isNextPayday)->toBeTrue();

    $nextCount = collect($days)->where('isNextPayday', true)->count();
    expect($nextCount)->toBe(1);

    CarbonImmutable::setTestNow();
});

test('past paydays in the visible grid are flagged isPastPayday', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15));

    $nextPayday = CarbonImmutable::create(2026, 6, 18);
    $previousPayday = $nextPayday->subWeek();

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => $nextPayday,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $previousCell = collect($days)->firstWhere('iso', $previousPayday->format('Y-m-d'));

    expect($previousCell)->not->toBeNull()
        ->and($previousCell->isPastPayday)->toBeTrue()
        ->and($previousCell->isNextPayday)->toBeFalse();

    CarbonImmutable::setTestNow();
});

test('users without pay cycle have no payday flags', function () {
    $user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $anyFlagged = collect($days)->contains(fn (CalendarDayData $d) => $d->isPastPayday || $d->isNextPayday);

    expect($anyFlagged)->toBeFalse();
});

// ── Active pay-cycle band ─────────────────────────────────────────

test('days inside the active pay cycle are flagged isInActiveCycle', function () {
    $today = CarbonImmutable::today();
    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $today->addDays(5),
    ]);

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $todayCell = collect($days)->firstWhere('iso', $today->format('Y-m-d'));

    expect($todayCell?->isInActiveCycle)->toBeTrue();
});

// ── Transfers ─────────────────────────────────────────────────────

test('transfer-pair transactions are excluded from pips', function () {
    $user = User::factory()->create();
    $fromAccount = Account::factory()->for($user)->create();
    $toAccount = Account::factory()->for($user)->create();
    $date = CarbonImmutable::now()->startOfMonth()->addDays(3);

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

    /** @var CalendarView $instance */
    $instance = Livewire::actingAs($user)
        ->test(CalendarView::class)
        ->instance();
    /** @var list<CalendarDayData> $days */
    $days = $instance->days();

    $cell = collect($days)->firstWhere('iso', $date->format('Y-m-d'));

    expect($cell->pips)->toBeEmpty();
});
