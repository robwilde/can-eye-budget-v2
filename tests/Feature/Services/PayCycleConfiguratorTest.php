<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\PayCycleConfigurator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * @return Collection<int, PlannedTransaction>
 */
function incomePlannedTransactions(User $user): Collection
{
    return PlannedTransaction::query()
        ->where('user_id', $user->id)
        ->where('is_pay_cycle_income', true)
        ->get();
}

beforeEach(function () {
    $this->configurator = app(PayCycleConfigurator::class);
    $this->user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
        'primary_account_id' => null,
    ]);
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
    $this->user->refresh();
});

test('persists the pay cycle on the user', function () {
    $nextPay = CarbonImmutable::today()->addDays(7)->format('Y-m-d');

    $this->configurator->apply($this->user, 300_000, PayFrequency::Fortnightly, $nextPay);

    $user = $this->user->fresh();

    expect($user->pay_amount)->toBe(300_000)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->next_pay_date->format('Y-m-d'))->toBe($nextPay);
});

test('creates exactly one income planned transaction with the correct attributes', function () {
    $nextPay = CarbonImmutable::today()->addDays(5)->format('Y-m-d');

    $this->configurator->apply($this->user, 250_000, PayFrequency::Monthly, $nextPay, 'ACME PAYROLL');

    $planned = incomePlannedTransactions($this->user);

    expect($planned)->toHaveCount(1);

    $income = $planned->first();

    expect($income->direction)->toBe(TransactionDirection::Credit)
        ->and($income->amount)->toBe(250_000)
        ->and($income->frequency)->toBe(RecurrenceFrequency::EveryMonth)
        ->and($income->start_date->format('Y-m-d'))->toBe($nextPay)
        ->and($income->account_id)->toBe($this->account->id)
        ->and($income->is_active)->toBeTrue()
        ->and($income->until_date)->toBeNull()
        ->and($income->description)->toBe('ACME PAYROLL');
});

test('defaults the income description to Salary when none is provided', function () {
    $this->configurator->apply(
        $this->user,
        200_000,
        PayFrequency::Weekly,
        CarbonImmutable::today()->addDay()->format('Y-m-d'),
    );

    expect(incomePlannedTransactions($this->user)->first()->description)->toBe('Salary');
});

test('re-applying updates the existing income row instead of duplicating it', function () {
    $this->configurator->apply(
        $this->user,
        300_000,
        PayFrequency::Fortnightly,
        CarbonImmutable::today()->addDays(7)->format('Y-m-d'),
    );

    $firstId = incomePlannedTransactions($this->user)->first()->id;

    $newNextPay = CarbonImmutable::today()->addDays(3)->format('Y-m-d');

    $this->configurator->apply($this->user->fresh(), 320_000, PayFrequency::Weekly, $newNextPay);

    $rows = incomePlannedTransactions($this->user);

    expect($rows)->toHaveCount(1);

    $income = $rows->first();

    expect($income->id)->toBe($firstId)
        ->and($income->amount)->toBe(320_000)
        ->and($income->frequency)->toBe(RecurrenceFrequency::EveryWeek)
        ->and($income->start_date->format('Y-m-d'))->toBe($newNextPay);
});

test('saves the pay columns but skips the income planned transaction when there is no primary account', function () {
    $user = User::factory()->create([
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
        'primary_account_id' => null,
    ]);

    $this->configurator->apply(
        $user,
        180_000,
        PayFrequency::Weekly,
        CarbonImmutable::today()->addDay()->format('Y-m-d'),
    );

    expect($user->fresh()->pay_amount)->toBe(180_000)
        ->and(incomePlannedTransactions($user))->toBeEmpty();
});

test('links the income to the seeded Income then Salary category when it exists', function () {
    $income = Category::factory()->create(['name' => 'Income']);
    $salary = Category::factory()->withParent($income)->create(['name' => 'Salary']);

    $this->configurator->apply(
        $this->user,
        300_000,
        PayFrequency::Fortnightly,
        CarbonImmutable::today()->addDays(7)->format('Y-m-d'),
    );

    expect(incomePlannedTransactions($this->user)->first()->category_id)->toBe($salary->id);
});

test('leaves the income uncategorised when the category tree is not seeded', function () {
    $this->configurator->apply(
        $this->user,
        300_000,
        PayFrequency::Fortnightly,
        CarbonImmutable::today()->addDays(7)->format('Y-m-d'),
    );

    expect(incomePlannedTransactions($this->user)->first()->category_id)->toBeNull();
});
