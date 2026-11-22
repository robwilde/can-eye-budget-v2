<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RecordTrade;
use App\Enums\TradeActivity;
use App\Models\Account;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\User;
use App\Services\AccountValuationService;
use App\Services\Investments\TradeRecorder;
use Carbon\CarbonImmutable;

function valuedInvestor(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->investment()->for($user)->create(['name' => 'Brokerage', 'balance' => 103000]);
    $security = Security::factory()->create(['ticker' => 'VASX', 'currency' => 'AUD']);
    $dayOne = CarbonImmutable::today()->subDays(4);
    app(AccountValuationService::class)->setOpeningBalance($account, 100000, $dayOne);
    app(AccountValuationService::class)->reconcile($account->fresh(), 103000, CarbonImmutable::today());
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => $dayOne->toDateString(), 'price' => 1500]);
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => $dayOne->addDays(2)->toDateString(), 'price' => 1800]);
    SecurityPrice::factory()->create(['security_id' => $security->id, 'currency' => 'AUD', 'date' => CarbonImmutable::today()->toDateString(), 'price' => 1800]);
    app(TradeRecorder::class)->record($user, new RecordTrade(accountId: $account->id, activity: TradeActivity::Buy, date: $dayOne->toDateString(), securityId: $security->id, quantity: '10', price: 1500));

    return [$user, $dayOne];
}

test('the net worth chart and report sections show the holdings valuation with the flag on', function () {
    config(['budget.investments.enabled' => true]);
    [$user, $dayOne] = valuedInvestor();
    $this->actingAs($user);

    visit('/dashboard')
        ->assertSee('Net worth')
        ->assertSee('VASX')
        ->assertNoJavaScriptErrors();

    $to = CarbonImmutable::today()->subDay()->toDateString();

    visit('/reports?period=custom&from='.$dayOne->toDateString().'&to='.$to)
        ->assertSee('+$30.00 over the period')
        ->assertSee('Investment performance')
        ->assertSee('VASX')
        ->assertNoJavaScriptErrors();
});
