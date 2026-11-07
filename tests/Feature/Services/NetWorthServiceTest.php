<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\User;
use App\Services\NetWorthService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->service = new NetWorthService;
});

function dailyBalance(Account $account, string $day, int $endBalance): AccountDailyBalance
{
    return AccountDailyBalance::factory()->for($account)->create([
        'balance_date' => $day,
        'start_balance' => $endBalance,
        'end_balance' => $endBalance,
    ]);
}

it('treats a mortgage as debt everywhere', function () {
    Account::factory()->for($this->user)->create(['balance' => 2000000]);
    Account::factory()->mortgage()->for($this->user)->create(['name' => 'Home loan', 'balance' => -50000000]);

    $sheet = $this->service->balanceSheet($this->user);

    expect($this->user->totalOwed())->toBe(50000000)
        ->and(array_map(fn ($group) => $group->class, $sheet->liabilities))->toBe([AccountClass::Mortgage])
        ->and(array_map(fn ($group) => $group->class, $sheet->assets))->toBe([AccountClass::Transaction])
        ->and($sheet->liabilityTotal)->toBe(-50000000)
        ->and($sheet->netWorth())->toBe(-48000000)
        ->and($this->service->current($this->user))->toBe(-48000000);
});

it('files a credit-limit account under liabilities whatever its type', function () {
    Account::factory()->for($this->user)->create(['type' => AccountClass::Transaction, 'balance' => -340642, 'credit_limit' => 500000]);

    $sheet = $this->service->balanceSheet($this->user);

    expect($sheet->assets)->toBe([])
        ->and($sheet->liabilityTotal)->toBe(-340642)
        ->and($this->user->totalOwed())->toBe(340642);
});

it('leaves excluded-from-reports accounts out of net worth', function () {
    $counted = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create(['balance' => 900000]);
    dailyBalance($counted, '2026-05-10', 100000);
    dailyBalance($excluded, '2026-05-10', 900000);

    $series = $this->service->series($this->user, CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-10'));
    $sheet = $this->service->balanceSheet($this->user);

    expect($this->service->current($this->user))->toBe(100000)
        ->and($series)->toBe(['2026-05-10' => 100000])
        ->and($sheet->assetTotal)->toBe(100000);
});

it('drops a disabled account from net worth from its disable date', function () {
    $active = Account::factory()->for($this->user)->create(['balance' => 50000]);
    $disabled = Account::factory()->inactive()->for($this->user)->create(['balance' => 100000, 'disabled_at' => '2026-05-15']);

    foreach (CarbonImmutable::parse('2026-05-01')->daysUntil('2026-06-01') as $day) {
        dailyBalance($active, $day->toDateString(), 50000);
        dailyBalance($disabled, $day->toDateString(), 100000);
    }

    $series = $this->service->series($this->user, CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'));

    expect($series['2026-05-01'])->toBe(150000)
        ->and($series['2026-05-14'])->toBe(150000)
        ->and($series['2026-05-15'])->toBe(50000)
        ->and($series['2026-05-31'])->toBe(50000)
        ->and($this->service->current($this->user))->toBe(50000);
});

it('computes net worth from the users own accounts only', function () {
    $other = User::factory()->create();
    $mine = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $theirs = Account::factory()->for($other)->create(['balance' => 700000]);
    Account::factory()->creditCard()->for($other)->create(['balance' => -20000]);
    dailyBalance($mine, '2026-05-10', 100000);
    dailyBalance($theirs, '2026-05-10', 700000);

    $day = CarbonImmutable::parse('2026-05-10');

    expect($this->service->current($this->user))->toBe(100000)
        ->and($this->service->series($this->user, $day, $day))->toBe(['2026-05-10' => 100000])
        ->and($this->service->balanceSheet($this->user)->assetTotal)->toBe(100000)
        ->and($this->service->accountSeries($this->user, [$mine->id, $theirs->id], $day, $day))->toBe([$mine->id => ['2026-05-10' => 100000]]);
});

it('sorts the balance sheet by the account order preference', function () {
    foreach ([['Alpha', 10000], ['Bravo', 30000], ['Charlie', 20000]] as [$name, $balance]) {
        Account::factory()->savings()->for($this->user)->create(['name' => $name, 'balance' => $balance]);
    }
    foreach ([['Visa', -500000], ['Amex', -10000]] as [$name, $balance]) {
        Account::factory()->creditCard()->for($this->user)->create(['name' => $name, 'balance' => $balance]);
    }

    $names = fn (array $groups): array => array_map(fn ($group) => array_map(fn (Account $account) => $account->name, $group->accounts), $groups)[0];

    $this->user->updatePreferences(['accountOrder' => 'balance-desc']);
    $sheet = $this->service->balanceSheet($this->user->fresh());

    expect($names($sheet->assets))->toBe(['Bravo', 'Charlie', 'Alpha'])
        ->and($names($sheet->liabilities))->toBe(['Amex', 'Visa']);

    $this->user->updatePreferences(['accountOrder' => 'balance-asc']);
    expect($names($this->service->balanceSheet($this->user->fresh())->assets))->toBe(['Alpha', 'Charlie', 'Bravo']);

    $this->user->updatePreferences(['accountOrder' => 'name-desc']);
    expect($names($this->service->balanceSheet($this->user->fresh())->assets))->toBe(['Charlie', 'Bravo', 'Alpha']);

    $this->user->updatePreferences(['accountOrder' => 'name-asc']);
    expect($names($this->service->balanceSheet($this->user->fresh())->assets))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('carries the last known balance across days without a row', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 40000]);
    dailyBalance($account, '2026-05-01', 25000);
    dailyBalance($account, '2026-05-12', 40000);

    $series = $this->service->series($this->user, CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-13'));

    expect($series)->toBe([
        '2026-05-10' => 25000,
        '2026-05-11' => 25000,
        '2026-05-12' => 40000,
        '2026-05-13' => 40000,
    ]);
});

it('carries the first row start balance back over days before it', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 40000]);
    AccountDailyBalance::factory()->for($account)->create([
        'balance_date' => '2026-05-12',
        'start_balance' => 25000,
        'end_balance' => 40000,
    ]);

    $series = $this->service->series($this->user, CarbonImmutable::parse('2026-05-10'), CarbonImmutable::parse('2026-05-12'));

    expect($series)->toBe(['2026-05-10' => 25000, '2026-05-11' => 25000, '2026-05-12' => 40000])
        ->and($this->service->accountSeries($this->user, [$account->id], CarbonImmutable::parse('2026-05-11'), CarbonImmutable::parse('2026-05-12')))
        ->toBe([$account->id => ['2026-05-11' => 25000, '2026-05-12' => 40000]]);
});

it('counts days before an opening-balance account first row as zero', function () {
    $account = Account::factory()->for($this->user)->create(['balance' => 40000]);
    AccountDailyBalance::factory()->for($account)->create([
        'balance_date' => '2026-05-12',
        'start_balance' => 0,
        'end_balance' => 40000,
    ]);

    $series = $this->service->series($this->user, CarbonImmutable::parse('2026-05-11'), CarbonImmutable::parse('2026-05-12'));

    expect($series)->toBe(['2026-05-11' => 0, '2026-05-12' => 40000]);
});

it('calls a rising balance favourable, so a falling debt is favourable too', function () {
    expect($this->service->isFavourable(['2026-05-01' => 100, '2026-05-02' => 200]))->toBeTrue()
        ->and($this->service->isFavourable(['2026-05-01' => 200, '2026-05-02' => 100]))->toBeFalse()
        ->and($this->service->isFavourable(['2026-05-01' => -500, '2026-05-02' => -200]))->toBeTrue()
        ->and($this->service->isFavourable(['2026-05-01' => -200, '2026-05-02' => -500]))->toBeFalse();
});
