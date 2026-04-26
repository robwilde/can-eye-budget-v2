<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\Data\PayCycleDayData;
use App\Livewire\Dashboard\PayCycleCalendar;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Constants\UnitValue;
use Livewire\Livewire;

function nextMondayAtLeastDaysAhead(int $minDaysAhead): CarbonImmutable
{
    $candidate = CarbonImmutable::today()->addDays($minDaysAhead);

    while ($candidate->dayOfWeek !== UnitValue::MONDAY) {
        $candidate = $candidate->addDay();
    }

    return $candidate;
}

test('shows empty-state when pay cycle is not configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->assertSee('Set up your pay cycle')
        ->assertSee(route('pay-cycle.edit'));
});

test('renders 14 days for fortnightly cycle starting Monday', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    expect($days)->toHaveCount(14);
});

test('renders 7 days for weekly cycle', function () {
    $nextPay = nextMondayAtLeastDaysAhead(3);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    expect($days)->toHaveCount(7);
});

test('renders ~30 days for monthly cycle', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Monthly,
        'next_pay_date' => CarbonImmutable::today()->addDays(15),
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    expect(count($days))->toBeGreaterThanOrEqual(27)
        ->and(count($days))->toBeLessThanOrEqual(31);
});

test('labels first day as PAID and last as PAYDAY', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    expect($days[0]->isCycleStart)->toBeTrue()
        ->and($days[count($days) - 1]->isCycleEnd)->toBeTrue();
});

test('today modifier set when cycle offset is 0 and today is in the cycle', function () {
    $nextPay = nextMondayAtLeastDaysAhead(3);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $todayIso = CarbonImmutable::today()->format('Y-m-d');
    $todayDay = collect($days)->first(fn (PayCycleDayData $day) => $day->iso === $todayIso);

    expect($todayDay?->isToday)->toBeTrue();
});

test('credits group as inc pips and debits as out pips', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $start = $nextPay->subWeeks(2);

    Transaction::factory()->credit()->for($user)->for($account)->create([
        'amount' => 50000,
        'post_date' => $start->addDay(),
    ]);
    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 12000,
        'post_date' => $start->addDays(2),
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $allPips = collect($days)->flatMap(fn (PayCycleDayData $day) => $day->pips);

    expect($allPips->where('kind', 'inc'))->toHaveCount(1)
        ->and($allPips->where('kind', 'out'))->toHaveCount(1);
});

test('planned transactions render as plan pips on occurrence dates', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $start = $nextPay->subWeeks(2);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => 'Rent',
        'direction' => TransactionDirection::Debit,
        'amount' => 60000,
        'start_date' => $start->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $planPips = collect($days)->flatMap(fn (PayCycleDayData $day) => $day->pips)->where('kind', 'plan');

    expect($planPips)->toHaveCount(1);
});

test('stores all pips and surfaces +N more hiddenCount for grid overflow', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $busyDay = $nextPay->subWeeks(2)->addDays(2);

    Transaction::factory()->debit()->for($user)->for($account)->count(5)->create([
        'amount' => 1000,
        'post_date' => $busyDay,
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $busyDayData = collect($days)->first(fn (PayCycleDayData $day) => $day->iso === $busyDay->format('Y-m-d'));

    expect($busyDayData?->pips)->toHaveCount(5)
        ->and($busyDayData?->hiddenCount)->toBe(2);
});

test('detail panel exposes all pips for a day even when more than the grid cap', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $busyDay = $nextPay->subWeeks(2)->addDays(2);
    $iso = $busyDay->format('Y-m-d');

    Transaction::factory()->debit()->for($user)->for($account)->count(5)->create([
        'amount' => 1000,
        'post_date' => $busyDay,
    ]);

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);
    $component->call('selectDay', $iso);

    $selected = $component->instance()->selectedDay();

    expect($selected['pips'] ?? [])->toHaveCount(5);
});

test('excludes transfer-pair transactions from pips', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $accountA = Account::factory()->for($user)->create();
    $accountB = Account::factory()->for($user)->create();

    $start = $nextPay->subWeeks(2);

    $debit = Transaction::factory()->debit()->for($user)->for($accountA)->create([
        'amount' => 30000,
        'post_date' => $start->addDay(),
    ]);
    $credit = Transaction::factory()->credit()->for($user)->for($accountB)->create([
        'amount' => 30000,
        'post_date' => $start->addDay(),
        'transfer_pair_id' => $debit->id,
    ]);
    $debit->update(['transfer_pair_id' => $credit->id]);

    Transaction::factory()->debit()->for($user)->for($accountA)->create([
        'amount' => 4000,
        'post_date' => $start->addDay(),
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $allPips = collect($days)->flatMap(fn (PayCycleDayData $day) => $day->pips);

    expect($allPips)->toHaveCount(1)
        ->and($allPips->first()->amount)->toBe(4000);
});

test('excludes transfer-categorised planned transactions', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();
    $transferCategory = Category::factory()->create(['name' => 'Transfer']);

    $start = $nextPay->subWeeks(2);

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'category_id' => $transferCategory->id,
        'direction' => TransactionDirection::Debit,
        'amount' => 50000,
        'start_date' => $start->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $planPips = collect($days)->flatMap(fn (PayCycleDayData $day) => $day->pips)->where('kind', 'plan');

    expect($planPips)->toHaveCount(0);
});

test('netCents per day equals credits minus debits', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $busyDay = $nextPay->subWeeks(2)->addDays(2);

    Transaction::factory()->credit()->for($user)->for($account)->create([
        'amount' => 100000,
        'post_date' => $busyDay,
    ]);
    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 35000,
        'post_date' => $busyDay,
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $busyDayData = collect($days)->first(fn (PayCycleDayData $day) => $day->iso === $busyDay->format('Y-m-d'));

    expect($busyDayData?->netCents)->toBe(65000);
});

test('previousCycle shifts a fortnightly cycle by 14 days', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);

    $currentBounds = $component->instance()->bounds();
    $component->call('previousCycle');
    $previousBounds = $component->instance()->bounds();

    expect($previousBounds['start']->equalTo($currentBounds['start']->subDays(14)))->toBeTrue()
        ->and($previousBounds['end']->equalTo($currentBounds['end']->subDays(14)))->toBeTrue();
});

test('previousCycle shifts a weekly cycle by 7 days', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Weekly,
        'next_pay_date' => nextMondayAtLeastDaysAhead(3),
    ]);
    Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);

    $currentBounds = $component->instance()->bounds();
    $component->call('previousCycle');
    $previousBounds = $component->instance()->bounds();

    expect($previousBounds['start']->equalTo($currentBounds['start']->subDays(7)))->toBeTrue();
});

test('previousCycle shifts a monthly cycle by one month', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Monthly,
        'next_pay_date' => CarbonImmutable::today()->addDays(15),
    ]);
    Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);

    $currentBounds = $component->instance()->bounds();
    $component->call('previousCycle');
    $previousBounds = $component->instance()->bounds();

    expect($previousBounds['start']->equalTo($currentBounds['start']->subMonthNoOverflow()))->toBeTrue()
        ->and($previousBounds['end']->equalTo($currentBounds['end']->subMonthNoOverflow()))->toBeTrue();
});

test('handles cycles spanning a year boundary', function () {
    $nextPay = CarbonImmutable::create(2027, 1, 5);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    expect($days)->toHaveCount(14)
        ->and($days[0]->iso)->toBe('2026-12-22')
        ->and(end($days)->iso)->toBe('2027-01-04');
});

test('isolates current user from other users transactions', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $otherUser = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();
    $otherAccount = Account::factory()->for($otherUser)->create();

    $start = $nextPay->subWeeks(2);

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 1000,
        'post_date' => $start->addDay(),
    ]);
    Transaction::factory()->debit()->for($otherUser)->for($otherAccount)->create([
        'amount' => 99999,
        'post_date' => $start->addDay(),
    ]);

    /** @var list<PayCycleDayData> $days */
    $days = Livewire::actingAs($user)
        ->test(PayCycleCalendar::class)
        ->instance()
        ->days();

    $allPips = collect($days)->flatMap(fn (PayCycleDayData $day) => $day->pips);

    expect($allPips)->toHaveCount(1)
        ->and($allPips->first()->amount)->toBe(1000);
});

test('selectDay updates selected day and surfaces it for the detail panel', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    $account = Account::factory()->for($user)->create();

    $targetDay = $nextPay->subWeeks(2)->addDays(3);
    $iso = $targetDay->format('Y-m-d');

    Transaction::factory()->debit()->for($user)->for($account)->create([
        'amount' => 7500,
        'post_date' => $targetDay,
    ]);

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);
    $component->call('selectDay', $iso);

    $selected = $component->instance()->selectedDay();

    expect($component->get('selectedDate'))->toBe($iso)
        ->and($selected['iso'] ?? null)->toBe($iso)
        ->and($selected['pips'] ?? [])->toHaveCount(1);
});

test('goToCurrentCycle resets cycle offset to 0 and selects today', function () {
    $nextPay = nextMondayAtLeastDaysAhead(7);

    $user = User::factory()->withPayCycle()->create([
        'pay_frequency' => PayFrequency::Fortnightly,
        'next_pay_date' => $nextPay,
    ]);
    Account::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test(PayCycleCalendar::class);

    $component->call('previousCycle')
        ->call('previousCycle');

    expect($component->get('cycleOffset'))->toBe(-2);

    $component->call('goToCurrentCycle');

    expect($component->get('cycleOffset'))->toBe(0)
        ->and($component->get('selectedDate'))->toBe(CarbonImmutable::today()->format('Y-m-d'));
});
