<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Console\Commands\SendBillAlertsCommand;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Jobs\SendBillAlertsJob;
use App\Models\Account;
use App\Models\BillAlertSend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\CashFlowWarningNotification;
use App\Notifications\SubscriptionAuditNotification;
use App\Services\Bills\BillAlertSender;
use App\Services\Bills\CashFlowForecaster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    useAppTimezone('UTC');
    Notification::fake();
    config(['budget.bill_alerts' => true, 'budget.low_balance_threshold' => 5000]);
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 1000000]);
    $this->alerts = app(BillAlertSender::class);
});

function alertPlan(object $test, string $name, int $cents, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create(['balance' => 1000000]);

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryWeek,
        'start_date' => '2030-01-01', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

function alertOccurrence(PlannedTransaction $plan, string $date, array $attributes = []): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, ...$attributes]);
}

function alertSpend(object $test, int $cents, string $date): Transaction
{
    return Transaction::factory()->fromRedbark()->for($test->user)->for($test->account)->create(['amount' => -$cents, 'direction' => TransactionDirection::Debit, 'post_date' => CarbonImmutable::parse($date), 'merchant_name' => 'Shop']);
}

it('notifies once for a subscription unpaid for a whole cycle', function () {
    alertOccurrence(alertPlan($this, 'Weekly bill', 2000), '2026-03-08');

    $result = $this->alerts->sendFor($this->user);

    expect($result['subscriptionAudits'])->toBe(1);

    Notification::assertSentToTimes($this->user, SubscriptionAuditNotification::class, 1);
    Notification::assertSentTo($this->user, SubscriptionAuditNotification::class, fn (SubscriptionAuditNotification $notification): bool => $notification->name === 'Weekly bill' && $notification->amount === 2000);

    $this->alerts->sendFor($this->user);

    Notification::assertSentToTimes($this->user, SubscriptionAuditNotification::class, 1);
});

it('does not notify before a whole cycle has passed or when the bill was paid', function () {
    alertOccurrence(alertPlan($this, 'Fresh', 2000), '2026-03-09');
    $paid = alertOccurrence(alertPlan($this, 'Part paid', 2000), '2026-03-01');
    PlannedPayment::factory()->create(['planned_occurrence_id' => $paid->id, 'transaction_id' => null, 'amount' => 2000, 'paid_on' => '2026-03-02']);
    $monthly = alertPlan($this, 'Monthly', 2000, ['frequency' => RecurrenceFrequency::EveryMonth]);
    alertOccurrence($monthly, '2026-03-01');

    expect($this->alerts->sendFor($this->user)['subscriptionAudits'])->toBe(0);

    Notification::assertNothingSentTo($this->user);
});

it('caps subscription audit notifications at three', function () {
    foreach (range(1, 5) as $index) {
        alertOccurrence(alertPlan($this, "Bill {$index}", 2000), '2026-03-0'.$index);
    }

    expect($this->alerts->sendFor($this->user)['subscriptionAudits'])->toBe(3);

    Notification::assertSentToTimes($this->user, SubscriptionAuditNotification::class, 3);

    $this->alerts->sendFor($this->user);

    Notification::assertSentToTimes($this->user, SubscriptionAuditNotification::class, 5);
});

it('leaves transfers and income out of subscription audits', function () {
    $savings = Account::factory()->for($this->user)->create();
    alertOccurrence(alertPlan($this, 'Sweep', 2000, ['transfer_to_account_id' => $savings->id]), '2026-03-01');
    alertOccurrence(alertPlan($this, 'Salary', 2000, ['direction' => TransactionDirection::Credit]), '2026-03-01');

    expect($this->alerts->sendFor($this->user)['subscriptionAudits'])->toBe(0);
});

it('warns when projected cash drops below the threshold', function () {
    $this->account->update(['balance' => 100000]);
    alertOccurrence(alertPlan($this, 'Rent', 60000, ['frequency' => RecurrenceFrequency::EveryMonth]), '2026-03-20');
    alertSpend($this, 31000, '2026-02-13');

    $forecast = app(CashFlowForecaster::class)->forecast($this->user);

    expect($forecast->startingCash)->toBe(100000)->and($forecast->billsDue)->toBe(60000)->and($forecast->dailySpend)->toBe(31000 / 31 * 1 | 0)->and($forecast->lowPoint)->toBe(100000 - 60000 - $forecast->dailySpend * 30);

    config(['budget.low_balance_threshold' => $forecast->lowPoint]);
    $this->alerts->sendFor($this->user);
    Notification::assertNothingSentTo($this->user);

    config(['budget.low_balance_threshold' => $forecast->lowPoint + 1]);
    $this->alerts->sendFor($this->user);

    Notification::assertSentTo($this->user, CashFlowWarningNotification::class, fn (CashFlowWarningNotification $notification): bool => ! $notification->isHighPriority() && $notification->lowPoint === $forecast->lowPoint);
});

it('marks the warning high priority when the low point is negative', function () {
    $this->account->update(['balance' => 100000]);
    alertOccurrence(alertPlan($this, 'Rent', 90000, ['frequency' => RecurrenceFrequency::EveryMonth]), '2026-03-20');
    alertSpend($this, 31000, '2026-02-13');

    $this->alerts->sendFor($this->user);

    Notification::assertSentTo($this->user, CashFlowWarningNotification::class, fn (CashFlowWarningNotification $notification): bool => $notification->isHighPriority() && $notification->lowPoint < 0);
});

it('counts every occurrence of a weekly bill and an overdue bill today', function () {
    $this->account->update(['balance' => 100000]);
    $weekly = alertPlan($this, 'Weekly', 1000);
    foreach (['2026-03-17', '2026-03-24', '2026-03-31', '2026-04-07'] as $date) {
        alertOccurrence($weekly, $date);
    }
    alertOccurrence(alertPlan($this, 'Overdue', 500), '2026-03-14');
    $partly = alertOccurrence(alertPlan($this, 'Partly', 3000), '2026-03-18');
    PlannedPayment::factory()->create(['planned_occurrence_id' => $partly->id, 'transaction_id' => null, 'amount' => 1000, 'paid_on' => '2026-03-16']);

    $forecast = app(CashFlowForecaster::class)->forecast($this->user);

    expect($forecast->billsDue)->toBe(4 * 1000 + 500 + 2000);
});

it('respects the recurring switch only for subscription audits', function () {
    $this->account->update(['balance' => 1000]);
    alertOccurrence(alertPlan($this, 'Weekly bill', 2000), '2026-03-08');
    $this->user->updatePreferences(['recurringEnabled' => false]);

    $result = $this->alerts->sendFor($this->user->fresh());

    expect($result)->toBe(['subscriptionAudits' => 0, 'cashFlowWarnings' => 1]);

    Notification::assertNotSentTo($this->user, SubscriptionAuditNotification::class);
    Notification::assertSentTo($this->user, CashFlowWarningNotification::class);
});

it('sends the cash flow warning once a day', function () {
    $this->account->update(['balance' => 1000]);

    $this->alerts->sendFor($this->user);
    $this->alerts->sendFor($this->user);

    Notification::assertSentToTimes($this->user, CashFlowWarningNotification::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-03-16 12:00:00', 'UTC'));
    $this->alerts->sendFor($this->user);

    Notification::assertSentToTimes($this->user, CashFlowWarningNotification::class, 2);
});

it('does not warn when there is no tracked cash or cash stays above the threshold', function () {
    $this->account->update(['balance' => 1000000]);
    expect($this->alerts->sendFor($this->user)['cashFlowWarnings'])->toBe(0);

    $this->account->update(['balance' => 1000, 'is_tracked' => false]);
    expect($this->alerts->sendFor($this->user->fresh())['cashFlowWarnings'])->toBe(0);
});

it('releases the claim when sending throws so the alert is retried', function () {
    $this->account->update(['balance' => 1000]);
    app()->instance(Illuminate\Contracts\Notifications\Dispatcher::class, new class implements Illuminate\Contracts\Notifications\Dispatcher
    {
        public function send($notifiables, $notification): void
        {
            throw new RuntimeException('mail down');
        }

        public function sendNow($notifiables, $notification, ?array $channels = null): void
        {
            throw new RuntimeException('mail down');
        }
    });

    expect(fn () => $this->alerts->sendFor($this->user))->toThrow(RuntimeException::class)->and(BillAlertSend::query()->count())->toBe(0);
});

it('notifies only the jobs user', function () {
    $other = User::factory()->create();
    alertOccurrence(alertPlan($this, 'Mine', 2000), '2026-03-08');
    alertOccurrence(alertPlan($this, 'Theirs', 2000, [], $other), '2026-03-08');
    $other->accounts()->update(['balance' => 1000]);
    $this->account->update(['balance' => 1000000]);

    new SendBillAlertsJob($this->user)->handle($this->alerts);

    Notification::assertSentTo($this->user, SubscriptionAuditNotification::class, fn (SubscriptionAuditNotification $notification): bool => $notification->name === 'Mine');
    Notification::assertNothingSentTo($other);
    expect(BillAlertSend::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('dispatches no alert jobs when the flag is off', function () {
    Bus::fake();
    config(['budget.bill_alerts' => false]);

    $this->artisan(SendBillAlertsCommand::class)->assertSuccessful();

    Bus::assertNotDispatched(SendBillAlertsJob::class);
});

it('queues one alert job per user with tracked cash when the flag is on', function () {
    Bus::fake();
    $noCash = User::factory()->create();
    Account::factory()->for($noCash)->creditCard()->create();

    $this->artisan(SendBillAlertsCommand::class)->assertSuccessful();

    Bus::assertDispatchedTimes(SendBillAlertsJob::class, 1);
    Bus::assertDispatched(SendBillAlertsJob::class, fn (SendBillAlertsJob $job): bool => $job->user->is($this->user));
});

it('does nothing in the job when the flag is off', function () {
    config(['budget.bill_alerts' => false]);
    $this->account->update(['balance' => 1000]);

    new SendBillAlertsJob($this->user)->handle($this->alerts);

    Notification::assertNothingSent();
});

it('schedules the command daily', function () {
    expect(collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'app:send-bill-alerts')))->toBeTrue();
});

it('counts only the current version of an edited transaction as spend', function () {
    alertSpend($this, 31000, '2026-02-13');
    $original = Transaction::query()->where('user_id', $this->user->id)->sole();
    $original->createChild(['category_id' => null, 'description' => 'Edited']);

    expect(app(CashFlowForecaster::class)->forecast($this->user)->dailySpend)->toBe(1000);
});

it('leaves a disabled account out of cash', function () {
    $disabled = Account::factory()->for($this->user)->create(['balance' => 50000000, 'status' => App\Enums\AccountStatus::Inactive]);
    $this->account->update(['balance' => 100000]);

    expect(app(CashFlowForecaster::class)->forecast($this->user)->startingCash)->toBe(100000);

    $this->account->update(['status' => App\Enums\AccountStatus::Inactive]);
    $disabled->update(['status' => App\Enums\AccountStatus::Inactive]);

    expect(app(CashFlowForecaster::class)->forecast($this->user))->toBeNull();
});

it('does not count history before the pay cycle as debt due today', function () {
    $this->account->update(['balance' => 100000]);
    $monthly = alertPlan($this, 'Old', 70000, ['frequency' => RecurrenceFrequency::EveryMonth]);
    alertOccurrence($monthly, '2025-11-10');
    alertOccurrence($monthly, '2026-03-10');

    expect(app(CashFlowForecaster::class)->forecast($this->user)->billsDue)->toBe(70000);
});

it('links to the dashboard unless the bills page is on and recurring is on', function () {
    $this->account->update(['balance' => 1000]);
    config(['budget.bills_page' => false]);

    expect(App\Support\Bills\BillsLink::for($this->user))->toBe(route('dashboard'));

    config(['budget.bills_page' => true]);

    expect(App\Support\Bills\BillsLink::for($this->user))->toBe(route('bills'));

    $this->user->updatePreferences(['recurringEnabled' => false]);

    expect(App\Support\Bills\BillsLink::for($this->user->fresh()))->toBe(route('dashboard'));
});

it('sends the mail inside the job rather than queueing a second job', function () {
    expect((new ReflectionClass(CashFlowWarningNotification::class))->implementsInterface(Illuminate\Contracts\Queue\ShouldQueue::class))->toBeFalse()
        ->and((new ReflectionClass(SubscriptionAuditNotification::class))->implementsInterface(Illuminate\Contracts\Queue\ShouldQueue::class))->toBeFalse();
});
