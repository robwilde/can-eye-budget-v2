<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\PlannedTransactionPriceChange;
use App\Models\User;
use App\Services\Bills\BillAuditor;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    useAppTimezone('UTC');
    $this->travelTo(CarbonImmutable::parse('2026-03-15 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->auditor = app(BillAuditor::class);
});

function auditPlan(object $test, string $name, int $cents, array $attributes = [], ?User $user = null): PlannedTransaction
{
    $user ??= $test->user;
    $account = $user->is($test->user) ? $test->account : Account::factory()->for($user)->create();

    return PlannedTransaction::factory()->for($user)->for($account)->create([
        'description' => $name, 'amount' => $cents, 'direction' => TransactionDirection::Debit, 'frequency' => RecurrenceFrequency::EveryMonth,
        'start_date' => '2026-01-10', 'until_date' => null, 'merchant_key' => null, ...$attributes,
    ]);
}

function auditOpen(PlannedTransaction $plan, string $date): PlannedOccurrence
{
    return PlannedOccurrence::factory()->for($plan, 'plannedTransaction')->create(['original_date' => $date, 'due_date' => $date, 'status' => OccurrenceStatus::Scheduled]);
}

it('flags only exact duplicates ignoring case and spacing', function () {
    auditPlan($this, 'Gym', 5000);
    auditPlan($this, ' gym ', 5000);
    auditPlan($this, 'Gym', 8000);
    auditPlan($this, 'Gym', 5000, ['start_date' => '2026-01-20']);

    $section = $this->auditor->audit($this->user)->sections['duplicates'];

    expect($section->count)->toBe(2)->and(collect($section->items)->pluck('label')->sort()->values()->all())->toBe([' gym ', 'Gym']);
});

it('measures overdue in each plans own cycle', function () {
    $weekly = auditPlan($this, 'Weekly', 1000, ['frequency' => RecurrenceFrequency::EveryWeek, 'start_date' => '2030-01-01']);
    $monthly = auditPlan($this, 'Monthly', 1000, ['start_date' => '2030-01-01']);
    auditOpen($weekly, '2026-03-07');
    auditOpen($monthly, '2026-03-07');

    $labels = collect($this->auditor->audit($this->user)->sections['overdue']->items)->pluck('label')->all();

    expect($labels)->toBe(['Weekly']);
});

it('rejects a look back outside one to twenty four months', function (int $months) {
    $this->auditor->audit($this->user, $months);
})->with([0, 25, -1, 100])->throws(ValidationException::class);

it('accepts the look back limits', function (int $months) {
    expect($this->auditor->audit($this->user, $months)->lookbackMonths)->toBe($months);
})->with([1, 24]);

it('caps each audit section at twenty items', function () {
    foreach (range(1, 25) as $index) {
        PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => auditPlan($this, "Bill {$index}", 1000 + $index)->id, 'effective_on' => '2026-03-01']);
    }

    $section = $this->auditor->audit($this->user)->sections['priceChanges'];

    expect($section->items)->toHaveCount(20)->and($section->count)->toBe(25)->and($section->truncated)->toBeTrue();
});

it('returns a disabled audit when recurring is off', function () {
    auditPlan($this, 'Gym', 5000);
    auditPlan($this, 'Gym', 5000);
    $this->user->updatePreferences(['recurringEnabled' => false]);

    $report = $this->auditor->audit($this->user->fresh());

    expect($report->enabled)->toBeFalse()->and($report->sections)->toBe([]);
});

it('never audits another users plans', function () {
    $other = User::factory()->create();
    auditPlan($this, 'Theirs', 5000, [], $other);
    auditPlan($this, 'Theirs', 5000, [], $other);
    $theirs = auditPlan($this, 'Theirs overdue', 5000, ['frequency' => RecurrenceFrequency::EveryWeek], $other);
    auditOpen($theirs, '2026-02-01');
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $theirs->id, 'effective_on' => '2026-03-01']);

    $report = $this->auditor->audit($this->user);

    expect(collect($report->sections)->sum('count'))->toBe(0);
});

it('lists price changes inside the look back only', function () {
    $plan = auditPlan($this, 'Phone', 5000);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'effective_on' => '2026-02-20']);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'effective_on' => '2025-06-01']);

    expect($this->auditor->audit($this->user, 3)->sections['priceChanges']->count)->toBe(1)->and($this->auditor->audit($this->user, 6)->sections['priceChanges']->count)->toBe(1)->and($this->auditor->audit($this->user, 12)->sections['priceChanges']->count)->toBe(2);
});

it('lists trials and renewals within thirty days and paused bills with open occurrences', function () {
    auditPlan($this, 'Trial', 1000, ['trial_ends_on' => '2026-03-20']);
    auditPlan($this, 'Late trial', 1000, ['trial_ends_on' => '2026-06-20']);
    auditPlan($this, 'Renewal', 1000, ['renewal_on' => '2026-04-10']);
    auditOpen(auditPlan($this, 'Paused', 1000, ['status' => PlannedTransactionStatus::Inactive]), '2026-03-20');

    $report = $this->auditor->audit($this->user);

    expect(collect($report->sections['trials']->items)->pluck('label')->all())->toBe(['Trial'])
        ->and(collect($report->sections['renewals']->items)->pluck('label')->all())->toBe(['Renewal'])
        ->and(collect($report->sections['pausedWithOpen']->items)->pluck('label')->all())->toBe(['Paused']);
});

it('lists undeclared recurring charges of this user only', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->for($other)->create();

    foreach ([[$this->user, $this->account], [$other, $otherAccount]] as [$owner, $account]) {
        foreach (['2026-01-15', '2026-02-15', '2026-03-12'] as $date) {
            App\Models\Transaction::factory()->fromRedbark()->for($owner)->for($account)->create(['merchant_name' => $owner->is($this->user) ? 'Netflix' : 'Stan', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'post_date' => CarbonImmutable::parse($date)]);
        }
    }

    $section = $this->auditor->audit($this->user)->sections['undeclared'];

    expect($section->count)->toBe(1)->and($section->items[0]['label'])->toContain('Netflix');
});

it('shows price changes as money', function () {
    $plan = auditPlan($this, 'Phone', 5000);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'previous_amount' => 5000, 'new_amount' => 6050, 'effective_on' => '2026-03-01']);

    expect($this->auditor->audit($this->user)->sections['priceChanges']->items[0]['detail'])->toBe('2026-03-01: $50.00 → $60.50');
});

it('does not flag yearly bills due in different months or an income and a bill with one name', function () {
    auditPlan($this, 'Car rego', 90000, ['frequency' => RecurrenceFrequency::EveryYear, 'start_date' => '2025-04-10']);
    auditPlan($this, 'Car rego', 90000, ['frequency' => RecurrenceFrequency::EveryYear, 'start_date' => '2025-09-10']);
    auditPlan($this, 'Refund', 5000, ['direction' => TransactionDirection::Credit]);
    auditPlan($this, 'Refund', 5000);

    expect($this->auditor->audit($this->user)->sections['duplicates']->count)->toBe(0);
});

it('does not list a charge waiting for confirmation as undeclared', function () {
    foreach (['2026-01-15', '2026-02-15', '2026-03-12'] as $date) {
        App\Models\Transaction::factory()->fromRedbark()->for($this->user)->for($this->account)->create(['merchant_name' => 'Netflix', 'amount' => 1699, 'direction' => TransactionDirection::Debit, 'post_date' => CarbonImmutable::parse($date)]);
    }

    app(App\Services\Recurring\RecurringSuggestionWriter::class)->writeForRun($this->user, App\Models\PipelineRun::factory()->for($this->user)->create(), app(App\Services\Recurring\RecurringTransactionDetector::class)->detect($this->user));
    $report = $this->auditor->audit($this->user);

    expect($report->sections['pendingDetections']->count)->toBe(1)->and($report->sections['undeclared']->count)->toBe(0);
});

it('leaves deleted detected plans out of price changes', function () {
    $plan = auditPlan($this, 'Gone', 5000);
    PlannedTransactionPriceChange::factory()->create(['planned_transaction_id' => $plan->id, 'effective_on' => '2026-03-01']);
    $plan->discard();

    expect($this->auditor->audit($this->user)->sections['priceChanges']->count)->toBe(0);
});
