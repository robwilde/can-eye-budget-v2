<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\ReportSection;
use App\Enums\TransactionDirection;
use App\Livewire\CategoryReport;
use App\Livewire\Reports\NetWorthSection;
use App\Livewire\Reports\TrendsSection;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\AccountValuation;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 1200000]);
});

function trendsFor(User $user, array $params = []): Testable
{
    return Livewire::actingAs($user->fresh())->test(TrendsSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31', ...$params]);
}

function netWorthFor(User $user, array $params = []): Testable
{
    return Livewire::actingAs($user->fresh())->test(NetWorthSection::class, ['from' => '2026-03-01', 'to' => '2026-03-31', ...$params]);
}

function sectionsRow(User $user, Account $account, string $date, int $amount, TransactionDirection $direction = TransactionDirection::Debit, ?Category $category = null): Transaction
{
    return Transaction::factory()->for($user)->for($account)->create([
        'amount' => $amount,
        'direction' => $direction,
        'post_date' => $date,
        'category_id' => $category?->id,
        'transfer_pair_id' => null,
    ]);
}

function sectionsHistory(Account $account, string $day, int $start, int $end): void
{
    AccountDailyBalance::factory()->for($account)->create(['balance_date' => $day, 'start_balance' => $start, 'end_balance' => $end]);
}

it('computes the monthly savings rate', function () {
    $salary = Category::factory()->for($this->user)->create(['name' => 'Salary']);
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    sectionsRow($this->user, $this->account, '2026-03-05', 400000, TransactionDirection::Credit, $salary);
    sectionsRow($this->user, $this->account, '2026-03-06', 300000, category: $food);

    $report = trendsFor($this->user);
    $march = collect($report->instance()->report()['months'])->firstWhere('key', '2026-03');

    expect($march['income'])->toBe(400000)
        ->and($march['expenses'])->toBe(300000)
        ->and($march['savingsRate'])->toBe(25);

    $report->assertSeeHtmlInOrder(['data-testid="trends-month-2026-03"', 'data-testid="savings-rate">25%', 'data-testid="trends-average"']);
});

it('shows no savings rate for a month without income', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    sectionsRow($this->user, $this->account, '2026-03-06', 1000, category: $food);

    $march = collect(trendsFor($this->user)->instance()->report()['months'])->firstWhere('key', '2026-03');

    expect($march['savingsRate'])->toBeNull();
});

it('compares weekday and weekend spending', function () {
    expect(CarbonImmutable::parse('2026-03-02')->isMonday())->toBeTrue();

    sectionsRow($this->user, $this->account, '2026-03-02', 1500);
    sectionsRow($this->user, $this->account, '2026-03-02', 2500);
    sectionsRow($this->user, $this->account, '2026-03-07', 4000);
    sectionsRow($this->user, $this->account, '2026-03-08', 6000);
    sectionsRow($this->user, $this->account, '2026-03-09', 99999, TransactionDirection::Credit);

    $report = trendsFor($this->user);
    $trends = $report->instance()->report();

    expect($trends['weekday'])->toBe(['total' => 4000, 'count' => 2, 'average' => 2000])
        ->and($trends['weekend'])->toBe(['total' => 10000, 'count' => 2, 'average' => 5000]);

    $report->assertSeeHtmlInOrder(['data-testid="weekday-average"', '$20.00', 'data-testid="weekend-average"', '$50.00']);
});

it('counts a split transaction once in the weekday and weekend averages', function () {
    $food = Category::factory()->for($this->user)->create(['name' => 'Food']);
    $fuel = Category::factory()->for($this->user)->create(['name' => 'Fuel']);
    $purchase = sectionsRow($this->user, $this->account, '2026-03-07', 10000);
    TransactionSplit::factory()->create(['transaction_id' => $purchase->id, 'category_id' => $food->id, 'amount' => 3000]);
    TransactionSplit::factory()->create(['transaction_id' => $purchase->id, 'category_id' => $fuel->id, 'amount' => 7000]);

    expect(trendsFor($this->user)->instance()->report()['weekend'])->toBe(['total' => 10000, 'count' => 1, 'average' => 10000]);
});

it('follows the report filters in the trends', function () {
    $other = Account::factory()->for($this->user)->create();
    sectionsRow($this->user, $this->account, '2026-03-07', 4000);
    sectionsRow($this->user, $other, '2026-03-07', 9000);

    expect(trendsFor($this->user)->instance()->report()['weekend']['total'])->toBe(13000)
        ->and(trendsFor($this->user, ['accountId' => $other->id])->instance()->report()['weekend']['total'])->toBe(9000);
});

it('shows the net worth change over the period', function () {
    sectionsHistory($this->account, '2026-02-15', 1000000, 1000000);
    sectionsHistory($this->account, '2026-03-10', 1000000, 1200000);

    $report = netWorthFor($this->user);
    $netWorth = $report->instance()->report();

    expect($netWorth['start'])->toBe(1000000)
        ->and($netWorth['end'])->toBe(1200000)
        ->and($netWorth['change'])->toBe(200000)
        ->and($netWorth['current'])->toBe(1200000);

    $report->assertSeeHtml('data-testid="net-worth-change"')->assertSee('+$2,000.00 over the period');
});

it('withholds the net worth change when history does not cover the period', function () {
    sectionsHistory($this->account, '2026-03-10', 1000000, 1200000);

    $report = netWorthFor($this->user);

    expect($report->instance()->report()['change'])->toBeNull();

    $report->assertSeeHtml('data-testid="net-worth-change-withheld"');
});

it('measures all time from the earliest balance history so start plus change is the end', function () {
    sectionsHistory($this->account, '2026-02-10', 1000000, 1000000);
    sectionsHistory($this->account, '2026-03-10', 1000000, 1200000);

    $netWorth = netWorthFor($this->user, ['from' => '2026-01-01', 'to' => '2026-03-31', 'allTime' => true])->instance()->report();

    expect($netWorth['from'])->toBe('2026-02-10')
        ->and($netWorth['start'])->toBe(1000000)
        ->and($netWorth['change'])->toBe(200000)
        ->and($netWorth['start'] + $netWorth['change'])->toBe($netWorth['end']);
});

it('does not draw an opening balance as growth from zero in the series', function () {
    $opening = Account::factory()->for($this->user)->create(['balance' => 500000]);
    AccountValuation::factory()->opening()->for($opening)->create(['valued_on' => '2026-03-10', 'balance' => 500000]);
    sectionsHistory($opening, '2026-03-10', 0, 500000);

    $netWorth = netWorthFor($this->user)->instance()->report();

    expect($netWorth['start'])->toBe(500000)
        ->and($netWorth['change'])->toBeNull();
});

it('draws a series for each net worth group and sums the accounts in a group', function () {
    $second = Account::factory()->for($this->user)->create(['balance' => 300000]);
    $mortgage = Account::factory()->mortgage()->for($this->user)->create(['balance' => -50000000]);
    sectionsHistory($this->account, '2026-02-15', 1000000, 1000000);
    sectionsHistory($this->account, '2026-03-10', 1000000, 1200000);
    sectionsHistory($second, '2026-02-15', 300000, 300000);
    sectionsHistory($mortgage, '2026-02-15', -50000000, -50000000);

    $groups = collect(netWorthFor($this->user)->instance()->report()['groups']);
    $asset = $groups->firstWhere('side', 'assets');
    $debt = $groups->firstWhere('side', 'liabilities');

    expect($asset['series']['2026-03-01'])->toBe(1300000)
        ->and($asset['series']['2026-03-20'])->toBe(1500000)
        ->and($asset['total'])->toBe(1500000)
        ->and($debt['series']['2026-03-01'])->toBe(-50000000)
        ->and($debt['total'])->toBe(-50000000);
});

it('keeps a class on both sides apart when a credit card is typed as a transaction account', function () {
    $card = Account::factory()->for($this->user)->create(['balance' => -80000, 'credit_limit' => 500000]);
    sectionsHistory($this->account, '2026-02-15', 1000000, 1000000);
    sectionsHistory($card, '2026-02-15', -80000, -80000);

    $groups = collect(netWorthFor($this->user)->instance()->report()['groups']);
    $asset = $groups->firstWhere('side', 'assets');
    $debt = $groups->firstWhere('side', 'liabilities');

    expect($asset['key'])->not->toBe($debt['key'])
        ->and($asset['series']['2026-03-01'])->toBe(1000000)
        ->and($debt['series']['2026-03-01'])->toBe(-80000);
});

it('saves the report section order per user', function () {
    $other = User::factory()->create();

    expect(Livewire::actingAs($this->user)->test(CategoryReport::class)->instance()->sections())->toBe([ReportSection::Spending, ReportSection::NetWorth, ReportSection::Trends]);

    Livewire::actingAs($this->user)->test(CategoryReport::class)->call('moveSection', 'trends', 'up');

    $order = Livewire::actingAs($this->user->fresh())->test(CategoryReport::class)
        ->assertSeeHtmlInOrder(['data-testid="report-section-spending"', 'data-testid="report-section-trends"', 'data-testid="report-section-net-worth"'])
        ->instance()->sections();

    expect($order)->toBe([ReportSection::Spending, ReportSection::Trends, ReportSection::NetWorth])
        ->and($other->fresh()->preferences->reportSectionOrder)->toBe([])
        ->and(ReportSection::ordered($other->fresh()->preferences->reportSectionOrder)[1])->toBe(ReportSection::NetWorth);

    $event = AuditEvent::query()->where('action', 'livewire.category-report.moveSection')->sole();

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('ignores moves that go past an end and keys it does not know', function () {
    $component = Livewire::actingAs($this->user)->test(CategoryReport::class)
        ->call('moveSection', 'spending', 'up')
        ->call('moveSection', 'trends', 'down')
        ->call('moveSection', 'nonsense', 'up')
        ->call('moveSection', 'trends', 'sideways');

    expect($this->user->fresh()->preferences->reportSectionOrder)->toBe([])
        ->and($component->instance()->sections())->toBe([ReportSection::Spending, ReportSection::NetWorth, ReportSection::Trends]);
});

it('reads a stored order with unknown keys and missing sections', function () {
    $this->user->updatePreferences(['reportSectionOrder' => ['trends', 'gone', 'trends']]);

    expect(Livewire::actingAs($this->user->fresh())->test(CategoryReport::class)->instance()->sections())
        ->toBe([ReportSection::Trends, ReportSection::Spending, ReportSection::NetWorth]);
});

it('builds report sections from the user\'s own data', function () {
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create(['name' => 'Stranger', 'balance' => 99900000]);
    $salary = Category::factory()->for($stranger)->create(['name' => 'Salary']);
    sectionsHistory($strangerAccount, '2026-02-01', 99900000, 99900000);
    sectionsHistory($strangerAccount, '2026-03-10', 99900000, 99950000);
    sectionsRow($stranger, $strangerAccount, '2026-03-02', 88800, TransactionDirection::Credit, $salary);
    sectionsRow($stranger, $strangerAccount, '2026-03-03', 77700);
    sectionsHistory($this->account, '2026-02-15', 1200000, 1200000);

    $netWorth = netWorthFor($this->user)->instance()->report();
    $trends = trendsFor($this->user)->instance()->report();

    expect($netWorth['current'])->toBe(1200000)
        ->and($netWorth['start'])->toBe(1200000)
        ->and($netWorth['end'])->toBe(1200000)
        ->and($netWorth['change'])->toBe(0)
        ->and(array_sum($netWorth['groups'][0]['series']) / count($netWorth['groups'][0]['series']))->toBe(1200000)
        ->and(count($netWorth['groups']))->toBe(1)
        ->and($trends['weekday']['count'] + $trends['weekend']['count'])->toBe(0)
        ->and(array_sum(array_column($trends['months'], 'income')))->toBe(0);
});

it('renders both sections on the page from the report period and filters', function () {
    sectionsRow($this->user, $this->account, '2026-03-07', 4000);

    Livewire::withQueryParams(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->actingAs($this->user)
        ->test(CategoryReport::class)
        ->assertSeeHtml('data-testid="report-net-worth"')
        ->assertSeeHtml('data-testid="trends-month-2026-03"')
        ->assertDontSeeHtml('data-testid="trends-month-2026-02"');
});

it('shows only the spending section in plan mode', function () {
    Livewire::actingAs($this->user)->test(CategoryReport::class)
        ->set('mode', 'plan')
        ->assertSeeHtml('data-testid="report-section-spending"')
        ->assertSeeHtml('data-testid="report-section-toggle-spending"')
        ->assertDontSeeHtml('data-testid="report-section-up-spending"')
        ->assertDontSeeHtml('data-testid="report-section-net-worth"')
        ->assertDontSeeHtml('data-testid="report-section-trends"');
});

it('does not rebuild the net worth section when the page changes something else', function () {
    sectionsHistory($this->account, '2026-02-15', 1000000, 1000000);

    $report = Livewire::withQueryParams(['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->actingAs($this->user)
        ->test(CategoryReport::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $report->call('toggleExpand', 1);
    $balanceQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'account_daily_balances'))->count();

    expect($balanceQueries)->toBe(0);

});
