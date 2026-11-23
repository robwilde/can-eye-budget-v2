<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\PlanAmountStrategy;
use App\Enums\PlannedTransactionType;
use App\Enums\PlanOrigin;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\PayCycleConfigurator;
use App\Services\SuggestionApplier;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

it('forces the transfer type on a plan with a destination account', function () {
    $plan = PlannedTransaction::factory()->bill()->transfer()->create();

    expect($plan->type)->toBe(PlannedTransactionType::Transfer)
        ->and($plan->fresh()->type)->toBe(PlannedTransactionType::Transfer);
});

it('forces the transfer type when a destination is added to an existing plan', function () {
    $plan = PlannedTransaction::factory()->subscription()->create();
    $destination = Account::factory()->for($plan->user)->create();

    $plan->update(['transfer_to_account_id' => $destination->id]);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Transfer);
});

it('keeps the declared type on a plan without a destination account', function () {
    $plan = PlannedTransaction::factory()->subscription()->create();

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Subscription);
});

it('refuses a transfer plan whose destination is its own account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    expect(fn () => PlannedTransaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'transfer_to_account_id' => $account->id,
    ]))->toThrow(ValidationException::class)
        ->and(PlannedTransaction::query()->count())->toBe(0);
});

it('refuses moving an existing plan onto its own account as destination', function () {
    $plan = PlannedTransaction::factory()->transfer()->create();

    expect(fn () => $plan->update(['transfer_to_account_id' => $plan->account_id]))
        ->toThrow(ValidationException::class)
        ->and($plan->fresh()->transfer_to_account_id)->not->toBe($plan->account_id);
});

it('refuses pointing an existing plan at the destination that is already its source account', function () {
    $plan = PlannedTransaction::factory()->transfer()->create();
    $destinationId = $plan->transfer_to_account_id;

    expect(fn () => $plan->update(['account_id' => $destinationId]))
        ->toThrow(ValidationException::class)
        ->and($plan->fresh()->account_id)->not->toBe($destinationId);
});

it('returns a plan to a bill when its destination is cleared', function () {
    $plan = PlannedTransaction::factory()->transfer()->create();

    $plan->update(['transfer_to_account_id' => null]);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Bill);
});

it('returns a credit plan to income when its destination is cleared', function () {
    $plan = PlannedTransaction::factory()->transfer()->create(['direction' => TransactionDirection::Credit]);

    $plan->update(['transfer_to_account_id' => null]);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Income);
});

it('keeps a non-transfer type when unrelated fields change', function () {
    $plan = PlannedTransaction::factory()->subscription()->create();

    $plan->update(['description' => 'Renamed']);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Subscription);
});

it('defaults plan tuning settings', function () {
    $user = User::factory()->create();

    $plan = PlannedTransaction::query()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->for($user)->create()->id,
        'amount' => 1500,
        'direction' => TransactionDirection::Debit,
        'description' => 'Gym',
        'start_date' => '2026-11-01',
        'frequency' => RecurrenceFrequency::EveryMonth,
    ])->fresh();

    expect($plan->reminder_days)->toBe(3)
        ->and($plan->grace_days)->toBe(3)
        ->and($plan->amount_tolerance_bps)->toBe(750)
        ->and($plan->match_early_days)->toBe(2)
        ->and($plan->match_late_days)->toBe(7)
        ->and($plan->amount_strategy)->toBe(PlanAmountStrategy::Fixed)
        ->and($plan->type)->toBe(PlannedTransactionType::Bill)
        ->and($plan->origin)->toBe(PlanOrigin::Declared)
        ->and($plan->autopay)->toBeFalse()
        ->and($plan->merchant_key)->toBeNull()
        ->and($plan->payment_url)->toBeNull()
        ->and($plan->notes)->toBeNull()
        ->and($plan->end_after_count)->toBeNull()
        ->and($plan->renewal_on)->toBeNull()
        ->and($plan->trial_ends_on)->toBeNull()
        ->and($plan->cancelled_on)->toBeNull();
});

it('stores the per-series settings and casts them', function () {
    $plan = PlannedTransaction::factory()->create([
        'type' => PlannedTransactionType::Subscription,
        'origin' => PlanOrigin::Detected,
        'merchant_key' => 'netflix',
        'payment_url' => 'https://netflix.example/pay',
        'notes' => 'Family plan',
        'autopay' => true,
        'end_after_count' => 12,
        'renewal_on' => '2027-01-01',
        'trial_ends_on' => '2026-12-01',
        'cancelled_on' => '2027-02-01',
        'reminder_days' => 5,
        'grace_days' => 1,
        'amount_tolerance_bps' => 100,
        'match_early_days' => 4,
        'match_late_days' => 9,
        'amount_strategy' => PlanAmountStrategy::LastPaid,
    ])->fresh();

    expect($plan->type)->toBe(PlannedTransactionType::Subscription)
        ->and($plan->origin)->toBe(PlanOrigin::Detected)
        ->and($plan->merchant_key)->toBe('netflix')
        ->and($plan->payment_url)->toBe('https://netflix.example/pay')
        ->and($plan->notes)->toBe('Family plan')
        ->and($plan->autopay)->toBeTrue()
        ->and($plan->end_after_count)->toBe(12)
        ->and($plan->renewal_on->toDateString())->toBe('2027-01-01')
        ->and($plan->trial_ends_on->toDateString())->toBe('2026-12-01')
        ->and($plan->cancelled_on->toDateString())->toBe('2027-02-01')
        ->and($plan->reminder_days)->toBe(5)
        ->and($plan->grace_days)->toBe(1)
        ->and($plan->amount_tolerance_bps)->toBe(100)
        ->and($plan->match_early_days)->toBe(4)
        ->and($plan->match_late_days)->toBe(9)
        ->and($plan->amount_strategy)->toBe(PlanAmountStrategy::LastPaid);
});

it('provides factory states for each plan type', function () {
    $subscription = PlannedTransaction::factory()->subscription()->create();
    $installment = PlannedTransaction::factory()->installment()->create();
    $income = PlannedTransaction::factory()->income()->create();

    expect(PlannedTransaction::factory()->bill()->create()->type)->toBe(PlannedTransactionType::Bill)
        ->and($subscription->type)->toBe(PlannedTransactionType::Subscription)
        ->and($subscription->autopay)->toBeTrue()
        ->and($installment->type)->toBe(PlannedTransactionType::Installment)
        ->and($installment->autopay)->toBeTrue()
        ->and($income->type)->toBe(PlannedTransactionType::Income)
        ->and($income->direction)->toBe(TransactionDirection::Credit);
});

it('accepts an end count from 1 to 600 or none', function (?int $count) {
    $plan = PlannedTransaction::factory()->create(['end_after_count' => $count]);

    expect($plan->fresh()->end_after_count)->toBe($count);
})->with([null, 1, 600]);

it('refuses an end count outside 1 to 600', function (int $count) {
    expect(fn () => PlannedTransaction::factory()->create(['end_after_count' => $count]))
        ->toThrow(ValidationException::class)
        ->and(PlannedTransaction::query()->count())->toBe(0);
})->with([0, -1, 601]);

it('marks a plan created from an accepted recurring suggestion as detected', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $suggestion = AnalysisSuggestion::factory()->create([
        'user_id' => $user->id,
        'type' => SuggestionType::RecurringTransaction,
        'payload' => [
            'account_id' => $account->id,
            'amount' => 1000,
            'direction' => TransactionDirection::Debit->value,
            'clean_description' => 'NETFLIX.COM',
            'start_date' => '2026-11-01',
            'frequency' => RecurrenceFrequency::EveryMonth->value,
            'matched_transaction_ids' => [],
        ],
    ]);

    $plan = app(SuggestionApplier::class)->applyRecurringTransaction($suggestion, $user, null);

    expect($plan->fresh()->origin)->toBe(PlanOrigin::Detected);
});

it('types a plan created from an accepted credit suggestion as income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $suggestion = AnalysisSuggestion::factory()->create([
        'user_id' => $user->id,
        'type' => SuggestionType::RecurringTransaction,
        'payload' => [
            'account_id' => $account->id,
            'amount' => 250000,
            'direction' => TransactionDirection::Credit->value,
            'clean_description' => 'RENT RECEIVED',
            'start_date' => '2026-11-01',
            'frequency' => RecurrenceFrequency::EveryMonth->value,
            'matched_transaction_ids' => [],
        ],
    ]);

    $plan = app(SuggestionApplier::class)->applyRecurringTransaction($suggestion, $user, null);

    expect($plan->fresh()->type)->toBe(PlannedTransactionType::Income);
});

it('types the pay cycle plan as income', function () {
    $user = User::factory()->create();
    $user->update(['primary_account_id' => Account::factory()->for($user)->create()->id]);

    app(PayCycleConfigurator::class)->apply($user, 300000, PayFrequency::Fortnightly, '2026-11-20');

    $plan = PlannedTransaction::query()->where('user_id', $user->id)->where('is_pay_cycle_income', true)->sole();

    expect($plan->type)->toBe(PlannedTransactionType::Income);
});

it('keeps plan type migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'identifier_probe', '', ['name' => 'identifier_probe', 'driver' => 'mariadb']);
    $originalSchema = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function () {
            (require database_path('migrations/2026_11_28_000000_add_series_fields_to_planned_transactions_table.php'))->up();
        });
    } finally {
        Schema::swap($originalSchema);
    }

    $identifiers = collect($queries)
        ->flatMap(fn (array $query) => preg_match_all('/`([^`]+)`/', $query['query'], $matches) ? $matches[1] : [])
        ->unique();

    expect($identifiers->filter(fn (string $name) => in_array($name, ['amount_tolerance_bps', 'end_after_count'], true)))->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $name) => mb_strlen($name) > 64)->values()->all())->toBeEmpty();
});
