<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountStatus;
use App\Livewire\Dashboard;
use App\Livewire\Dashboard\BalanceSheet;
use App\Livewire\Dashboard\NetWorthChart;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetWorthService;
use App\Support\Dashboard\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Reactive;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00'));
    $this->user = User::factory()->withPayCycle()->create();
    $this->service = app(NetWorthService::class);
});

function netWorthPeriod(User $user, array $preferences = []): DashboardPeriod
{
    $user->updatePreferences($preferences);

    return DashboardPeriod::for($user->fresh());
}

function netWorthHistory(Account $account, string $day, int $start, int $end): void
{
    AccountDailyBalance::factory()->for($account)->create(['balance_date' => $day, 'start_balance' => $start, 'end_balance' => $end]);
}

it('computes net worth as the signed sum of active balances', function () {
    Account::factory()->for($this->user)->create(['balance' => 500000]);
    Account::factory()->creditCard()->for($this->user)->create(['balance' => -120000, 'credit_limit' => 500000]);

    expect($this->service->current($this->user))->toBe(380000);
});

it('counts mortgages as liabilities everywhere', function () {
    Account::factory()->for($this->user)->create(['balance' => 1000000]);
    Account::factory()->mortgage()->for($this->user)->create(['name' => 'Home loan', 'balance' => -30000000]);

    expect($this->user->totalOwed())->toBe(30000000)
        ->and($this->service->balanceSheet($this->user)->liabilityTotal)->toBe(-30000000);

    Livewire::actingAs($this->user)
        ->test(BalanceSheet::class)
        ->assertSeeHtmlInOrder(['data-test="balance-sheet-liabilities"', 'Mortgage', 'Home loan', '-$300,000.00']);
});

it('withholds velocity when history does not cover the window', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    AccountValuation::factory()->opening()->for($account)->create(['valued_on' => '2026-07-05', 'balance' => 100000]);
    netWorthHistory($account, '2026-07-05', 0, 100000);

    $period = netWorthPeriod($this->user);
    $series = $this->service->chartSeries($this->user, $period);

    expect($this->service->velocity($this->user, $period))->toBeNull()
        ->and($series[array_key_first($series)])->toBe(100000)
        ->and(array_unique(array_values($series)))->toBe([100000]);

    Livewire::actingAs($this->user)
        ->test(NetWorthChart::class)
        ->assertSeeHtml('data-test="net-worth-velocity-withheld"')
        ->assertDontSeeHtml('data-test="net-worth-velocity"');
});

it('reports how far net worth moved when history covers the whole window', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 130000]);
    netWorthHistory($account, '2026-06-01', 100000, 100000);
    netWorthHistory($account, '2026-07-10', 100000, 130000);

    $period = netWorthPeriod($this->user);

    expect($this->service->velocity($this->user, $period))->toBe(30000);

    Livewire::actingAs($this->user)
        ->test(NetWorthChart::class)
        ->assertSeeHtml('data-test="net-worth-velocity"')
        ->assertSee('+$300.00 over Last 30 Days');
});

it('withholds velocity when one of two accounts has no history reaching back', function () {
    $covered = Account::factory()->for($this->user)->create(['balance' => 100000]);
    Account::factory()->for($this->user)->create(['balance' => 50000]);
    netWorthHistory($covered, '2026-05-01', 100000, 100000);

    expect($this->service->velocity($this->user, netWorthPeriod($this->user)))->toBeNull();
});

it('draws a balance that was really zero before the window as growth from zero', function () {
    $card = Account::factory()->creditCard()->for($this->user)->create(['balance' => -20000, 'credit_limit' => 500000]);
    netWorthHistory($card, '2026-06-01', 0, 0);
    netWorthHistory($card, '2026-07-10', 0, -20000);

    $period = netWorthPeriod($this->user);
    $series = $this->service->chartSeries($this->user, $period);

    expect($series[array_key_first($series)])->toBe(0)
        ->and($series[array_key_last($series)])->toBe(-20000)
        ->and($this->service->velocity($this->user, $period))->toBe(-20000)
        ->and($this->service->isFavourable($series))->toBeFalse();
});

it('does not back-date an account that was funded from another account', function () {
    $checking = Account::factory()->for($this->user)->create(['balance' => 0]);
    $savings = Account::factory()->savings()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($checking, '2026-06-01', 100000, 100000);
    netWorthHistory($checking, '2026-07-05', 100000, 0);
    netWorthHistory($savings, '2026-07-05', 0, 100000);

    $series = $this->service->chartSeries($this->user, netWorthPeriod($this->user));

    expect(array_unique(array_values($series)))->toBe([100000]);
});

it('measures change from a history that starts on the first day of the window', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($account, '2026-06-15', 100000, 100000);

    $period = netWorthPeriod($this->user);

    expect($this->service->velocity($this->user, $period))->toBe(0)
        ->and($this->service->chartSeries($this->user, $period)['2026-06-15'])->toBe(100000);
});

it('leaves a closed account out of the chart and out of the history check', function () {
    $open = Account::factory()->for($this->user)->create(['balance' => 100000]);
    Account::factory()->for($this->user)->create(['balance' => 900000, 'status' => AccountStatus::Closed]);
    netWorthHistory($open, '2026-05-01', 100000, 100000);

    $period = netWorthPeriod($this->user);

    expect($this->service->velocity($this->user, $period))->toBe(0)
        ->and(array_unique(array_values($this->service->chartSeries($this->user, $period))))->toBe([100000]);
});

it('ends a custom range at today instead of drawing future days', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($account, '2026-01-01', 100000, 100000);

    $series = $this->service->chartSeries($this->user, netWorthPeriod($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2026-07-01', 'dashboardTo' => '2026-12-31']));

    expect(array_key_last($series))->toBe('2026-07-15')
        ->and(array_key_first($series))->toBe('2026-07-01');

    $future = $this->service->chartSeries($this->user, netWorthPeriod($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2027-01-01', 'dashboardTo' => '2027-02-01']));

    expect(array_keys($future))->toBe(['2026-07-15']);
});

it('measures all time from the earliest balance history', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 130000]);
    netWorthHistory($account, '2025-03-10', 100000, 100000);
    netWorthHistory($account, '2026-07-01', 100000, 130000);
    Transaction::factory()->for($this->user)->create(['account_id' => $account->id, 'post_date' => '2025-03-10']);

    $period = netWorthPeriod($this->user, ['dashboardPeriod' => 'all']);

    expect($this->service->velocity($this->user, $period))->toBe(30000);
});

it('converts only the plotted days into the base currency', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $user = User::factory()->create(['base_currency' => 'USD']);
    $account = Account::factory()->for($user)->create(['balance' => 100000, 'currency' => 'EUR']);
    netWorthHistory($account, '2024-01-01', 100000, 100000);
    ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2016-01-01', 'rate' => 1.10]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $series = $this->service->chartSeries($user, netWorthPeriod($user, ['dashboardPeriod' => '10y']));
    $rateQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'exchange_rates'))->count();

    expect(count($series))->toBeBetween(120, 122)
        ->and($rateQueries)->toBeLessThan(500);
});

it('excludes closed accounts and other users\' accounts', function () {
    Account::factory()->for($this->user)->create(['balance' => 500000]);
    Account::factory()->for($this->user)->create(['balance' => 900000, 'status' => AccountStatus::Closed]);
    Account::factory()->for(User::factory()->create())->create(['balance' => 700000]);

    expect($this->service->current($this->user))->toBe(500000)
        ->and($this->service->balanceSheet($this->user)->netWorth())->toBe(500000);
});

it('builds the net worth series at the period granularity', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($account, '2023-06-01', 100000, 100000);

    $weekly = $this->service->chartSeries($this->user, netWorthPeriod($this->user, ['dashboardPeriod' => 'custom', 'dashboardFrom' => '2024-01-01', 'dashboardTo' => '2026-01-01']));
    $days = array_keys($weekly);
    $sundays = array_slice($days, 1, -1);
    $gaps = [];

    foreach (array_keys($sundays) as $index) {
        if ($index > 0) {
            $gaps[] = (int) CarbonImmutable::parse($sundays[$index - 1])->diffInDays(CarbonImmutable::parse($sundays[$index]));
        }
    }

    expect($days[0])->toBe('2024-01-01')
        ->and(end($days))->toBe('2026-01-01')
        ->and(count($weekly))->toBeBetween(104, 108)
        ->and(array_unique(array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('l'), $sundays)))->toBe(['Sunday'])
        ->and(array_unique($gaps))->toBe([7]);

    $monthly = $this->service->chartSeries($this->user, netWorthPeriod($this->user, ['dashboardPeriod' => '10y', 'dashboardFrom' => null, 'dashboardTo' => null]));
    $daily = $this->service->chartSeries($this->user, netWorthPeriod($this->user, ['dashboardPeriod' => '1d']));

    expect(count($monthly))->toBeBetween(120, 122)
        ->and(array_keys($daily))->toBe(['2026-07-14', '2026-07-15']);
});

it('leaves other users out of the chart series', function () {
    $other = Account::factory()->for(User::factory()->create())->create(['balance' => 900000]);
    netWorthHistory($other, '2026-06-01', 900000, 900000);
    $mine = Account::factory()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($mine, '2026-06-01', 100000, 100000);

    $series = $this->service->chartSeries($this->user, netWorthPeriod($this->user));

    expect(array_unique(array_values($series)))->toBe([100000]);
});

it('follows the dashboard period when the user picks another', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    netWorthHistory($account, '2026-01-01', 100000, 100000);

    $component = Livewire::actingAs($this->user)->test(NetWorthChart::class);

    expect(count($component->instance()->series()))->toBe(31)
        ->and((new ReflectionProperty(NetWorthChart::class, 'periodStamp'))->getAttributes(Reactive::class))->toHaveCount(1);

    $this->user->updatePreferences(['dashboardPeriod' => '7d']);

    $component->call('$refresh');

    expect(count($component->instance()->series()))->toBe(8);
});

it('maps a layout saved with the old net worth widget onto the chart and the balance sheet', function () {
    Account::factory()->for($this->user)->create(['balance' => 100000]);
    DB::table('users')->where('id', $this->user->id)->update(['preferences' => json_encode(['dashboardLayout' => ['hidden' => ['net-worth'], 'order' => ['recent-spending', 'net-worth']]])]);

    $component = Livewire::actingAs($this->user->fresh())->test(Dashboard::class);

    $visible = array_map(static fn ($widget): string => $widget->value, $component->instance()->visibleWidgets());

    expect($visible)->not->toContain('net-worth-chart')->not->toContain('balance-sheet');

    $component->assertDontSeeLivewire(NetWorthChart::class)->assertDontSeeLivewire(BalanceSheet::class);
});

it('shows the chart and the balance sheet as two lazy widgets on the dashboard', function () {
    Account::factory()->for($this->user)->create(['balance' => 100000]);

    Livewire::actingAs($this->user)
        ->test(Dashboard::class)
        ->assertSeeLivewire(NetWorthChart::class)
        ->assertSeeLivewire(BalanceSheet::class);
});
