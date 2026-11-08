<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard;
use App\Livewire\TransactionList;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Reports\ReportAggregator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-15'));
    $this->user = User::factory()->create();
});

it('leaves excluded-from-reports accounts out of user totals', function () {
    Account::factory()->for($this->user)->create(['balance' => 100000]);
    Account::factory()->for($this->user)->excludedFromReports()->create(['balance' => 50000]);

    expect($this->user->totalAvailable())->toBe(100000);
});

it('leaves excluded-from-reports cards out of the amount owed', function () {
    Account::factory()->for($this->user)->creditCard()->create(['balance' => -30000, 'credit_limit' => 100000]);
    Account::factory()->for($this->user)->creditCard()->excludedFromReports()->create(['balance' => -70000, 'credit_limit' => 100000]);

    expect($this->user->totalOwed())->toBe(30000);
});

it('leaves excluded-from-reports accounts out of report atoms', function () {
    $included = Account::factory()->for($this->user)->create();
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();

    Transaction::factory()->for($this->user)->debit()->create(['account_id' => $included->id, 'amount' => 5000, 'post_date' => '2026-07-05']);
    Transaction::factory()->for($this->user)->debit()->create(['account_id' => $excluded->id, 'amount' => 9000, 'post_date' => '2026-07-06']);

    $atoms = app(ReportAggregator::class)->atoms($this->user, 'actual', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')->endOfDay());

    expect(array_sum(array_column($atoms, 'total')))->toBe(5000)
        ->and(array_sum(array_column($atoms, 'count')))->toBe(1);
});

it('leaves split lines of excluded-from-reports accounts out of report atoms', function () {
    $included = Account::factory()->for($this->user)->create();
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();

    $includedTransaction = Transaction::factory()->for($this->user)->debit()->create(['account_id' => $included->id, 'amount' => 5000, 'post_date' => '2026-07-05']);
    $excludedTransaction = Transaction::factory()->for($this->user)->debit()->create(['account_id' => $excluded->id, 'amount' => 9000, 'post_date' => '2026-07-06']);

    foreach ([$includedTransaction, $excludedTransaction] as $transaction) {
        $half = intdiv(abs($transaction->amount), 2);

        $transaction->splits()->create(['category_id' => null, 'amount' => $half, 'position' => 1]);
        $transaction->splits()->create(['category_id' => null, 'amount' => abs($transaction->amount) - $half, 'position' => 2]);
    }

    $atoms = app(ReportAggregator::class)->atoms($this->user, 'actual', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')->endOfDay());

    expect(array_sum(array_column($atoms, 'total')))->toBe(5000)
        ->and(array_sum(array_column($atoms, 'count')))->toBe(2);
});

it('counts a planned transfer by the tracked side that is not excluded from reports', function () {
    $included = Account::factory()->for($this->user)->create();
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();
    $hidden = Account::factory()->for($this->user)->untracked()->create();

    $plan = fn (Account $from, Account $to, int $amount) => PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $from->id,
        'transfer_to_account_id' => $to->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
        'start_date' => '2026-07-10',
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $plan($hidden, $excluded, 4000);
    $plan($excluded, $hidden, 5000);
    $plan($hidden, $included, 2000);
    $plan($included, $hidden, 700);

    $totals = collect(app(ReportAggregator::class)->atoms($this->user, 'plan', CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31')->endOfDay()))
        ->groupBy('direction')
        ->map(fn ($rows) => $rows->sum('total'))
        ->all();

    expect($totals)->toEqual(['credit' => 2000, 'debit' => 700]);
});

it('leaves planned debits of excluded-from-reports accounts out of the needed total', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    $included = Account::factory()->for($user)->create();
    $excluded = Account::factory()->for($user)->excludedFromReports()->create();
    $hidden = Account::factory()->for($user)->untracked()->create();

    $plan = fn (Account $account, ?Account $to, int $amount) => PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'transfer_to_account_id' => $to?->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $plan($included, null, 1200);
    $plan($excluded, null, 9000);
    $plan($excluded, $hidden, 7000);

    expect($user->totalNeededUntilPayday())->toBe(1200);
});

it('reserves a planned transfer into an excluded-from-reports account in the needed total', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    $included = Account::factory()->for($user)->create();
    $otherIncluded = Account::factory()->for($user)->create();
    $excluded = Account::factory()->for($user)->excludedFromReports()->create();

    $transfer = fn (Account $from, Account $to, int $amount) => PlannedTransaction::factory()->for($user)->create([
        'account_id' => $from->id,
        'transfer_to_account_id' => $to->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
        'is_active' => true,
        'start_date' => now()->addDay(),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $transfer($included, $excluded, 6000);
    $transfer($included, $otherIncluded, 3000);
    $transfer($excluded, $included, 8000);

    expect($user->totalNeededUntilPayday())->toBe(6000);
});

it('leaves debits of excluded-from-reports accounts out of budgets this cycle', function () {
    $user = User::factory()->withPayCycle()->create(['next_pay_date' => now()->addDays(7)]);
    $included = Account::factory()->for($user)->create();
    $excluded = Account::factory()->for($user)->excludedFromReports()->create();
    $category = Category::factory()->for($user)->create();
    Budget::factory()->for($user)->create(['category_id' => $category->id, 'name' => 'Groceries', 'limit_amount' => 80000]);

    foreach ([[$included, 1500], [$excluded, 9000]] as [$account, $amount]) {
        Transaction::factory()->debit()->for($user)->for($account)->create([
            'amount' => $amount,
            'post_date' => now(),
            'category_id' => $category->id,
        ]);
    }

    $row = Livewire::actingAs($user)->test(Dashboard::class)->instance()->budgetsThisCycle()->sole();

    expect($row['spent'])->toBe(1500);
});

it('leaves plans of excluded-from-reports accounts out of the monthly projection', function () {
    $included = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();
    $hidden = Account::factory()->for($this->user)->untracked()->create();
    $this->user->update(['primary_account_id' => $included->id]);

    $plan = fn (Account $account, ?Account $to, int $amount) => PlannedTransaction::factory()->for($this->user)->create([
        'account_id' => $account->id,
        'transfer_to_account_id' => $to?->id,
        'direction' => TransactionDirection::Debit,
        'amount' => $amount,
        'is_active' => true,
        'start_date' => now()->addDays(2),
        'frequency' => RecurrenceFrequency::DontRepeat,
    ]);

    $plan($included, null, 1000);
    $plan($excluded, null, 9000);
    $plan($excluded, $hidden, 7000);
    $plan($hidden, $excluded, 5000);

    $projection = app(MonthlyProjectionService::class)->forUser($this->user->fresh());

    expect(array_map(fn ($point) => $point->eventAmountCents, $projection->points))->toBe([0, -1000])
        ->and($projection->points[1]->balanceCents)->toBe(99000);
});

it('still lists transactions of an excluded-from-reports account', function () {
    $excluded = Account::factory()->for($this->user)->excludedFromReports()->create();

    Transaction::factory()->for($this->user)->debit()->create([
        'account_id' => $excluded->id,
        'description' => 'HIDDEN ACCOUNT PURCHASE',
        'post_date' => '2026-07-10',
    ]);

    Livewire::actingAs($this->user)
        ->test(TransactionList::class)
        ->assertSee('HIDDEN ACCOUNT PURCHASE');
});
