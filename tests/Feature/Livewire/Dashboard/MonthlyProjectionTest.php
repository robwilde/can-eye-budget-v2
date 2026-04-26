<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Livewire\Dashboard\MonthlyProjection;
use App\Models\Account;
use App\Models\PlannedTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('renders empty-state when pay cycle is not configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(MonthlyProjection::class)
        ->assertSee('12-month projection unavailable');
});

test('renders chart canvas when projection has data', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 200000,
        'pay_frequency' => PayFrequency::Fortnightly,
    ]);

    Livewire::actingAs($user)
        ->test(MonthlyProjection::class)
        ->assertSee('Next 12 months')
        ->assertSeeHtml('wire:ignore');
});

test('surfaces the closest risky month in a warning banner', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);
    $account = Account::factory()->for($user)->create();

    PlannedTransaction::factory()->for($user)->for($account)->create([
        'direction' => TransactionDirection::Debit,
        'amount' => 250000,
        'start_date' => CarbonImmutable::today()->startOfMonth()->addDays(3),
        'frequency' => RecurrenceFrequency::DontRepeat,
        'is_active' => true,
    ]);

    Livewire::actingAs($user)
        ->test(MonthlyProjection::class)
        ->assertSee('first month where planned spend exceeds projected income');
});

test('chart payload categories label January with year for non-current January', function () {
    $user = User::factory()->withPayCycle()->create([
        'pay_amount' => 100000,
        'pay_frequency' => PayFrequency::Monthly,
    ]);

    $payload = Livewire::actingAs($user)
        ->test(MonthlyProjection::class)
        ->instance()
        ->chartPayload();

    $hasYearLabel = collect($payload['categories'])
        ->skip(1)
        ->contains(fn (string $label) => str_contains($label, ' '));

    expect($hasYearLabel)->toBeTrue();
});
