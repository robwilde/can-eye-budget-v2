<?php

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Support\Insights\CountableFlow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function insightFlowDebit(User $user, array $attributes): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => Account::query()->where('user_id', $user->id)->firstOrFail()->id,
        'direction' => TransactionDirection::Debit,
        'post_date' => '2026-10-10',
        ...$attributes,
    ]);
}

function insightFlowRange(): array
{
    return [CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')];
}

it('counts rows posted on the last day of the range', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    insightFlowDebit($user, ['amount' => 1000, 'post_date' => '2026-10-31']);
    insightFlowDebit($user, ['amount' => 2000, 'post_date' => '2026-10-01']);
    insightFlowDebit($user, ['amount' => 4000, 'post_date' => '2026-11-01']);
    insightFlowDebit($user, ['amount' => 8000, 'post_date' => '2026-09-30']);

    [$from, $to] = insightFlowRange();

    expect(CountableFlow::total($user, TransactionDirection::Debit, $from, $to))->toBe(3000);
});

it('counts a groceries split line on a transfer-category row', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $transfer = Category::factory()->create(['user_id' => $user->id, 'name' => 'Transfer']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $row = insightFlowDebit($user, ['amount' => 10000, 'category_id' => $transfer->id]);
    TransactionSplit::factory()->create(['transaction_id' => $row->id, 'category_id' => $groceries->id, 'amount' => 6000]);
    TransactionSplit::factory()->create(['transaction_id' => $row->id, 'category_id' => $transfer->id, 'amount' => 4000]);

    [$from, $to] = insightFlowRange();

    expect(CountableFlow::byCategory($user, TransactionDirection::Debit, $from, $to))->toBe([$groceries->id => 6000]);
});

it('drops a transfer split line on a groceries row', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    $transfer = Category::factory()->create(['user_id' => $user->id, 'name' => 'Transfer']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $row = insightFlowDebit($user, ['amount' => 10000, 'category_id' => $groceries->id]);
    TransactionSplit::factory()->create(['transaction_id' => $row->id, 'category_id' => $groceries->id, 'amount' => 7000]);
    TransactionSplit::factory()->create(['transaction_id' => $row->id, 'category_id' => $transfer->id, 'amount' => 3000]);

    [$from, $to] = insightFlowRange();

    expect(CountableFlow::byCategory($user, TransactionDirection::Debit, $from, $to))->toBe([$groceries->id => 7000])
        ->and(CountableFlow::total($user, TransactionDirection::Debit, $from, $to))->toBe(7000);
});

it('leaves out accounts excluded from reports and converts other currencies', function () {
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => false]);
    $user = User::factory()->create(['base_currency' => 'USD']);
    $usd = Account::factory()->for($user)->create(['currency' => 'USD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    $hidden = Account::factory()->for($user)->create(['currency' => 'USD', 'exclude_from_reports' => true]);
    App\Models\ExchangeRate::factory()->create(['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate_date' => '2026-10-10', 'rate' => 2.0]);
    insightFlowDebit($user, ['account_id' => $usd->id, 'amount' => 1000, 'currency' => 'USD']);
    insightFlowDebit($user, ['account_id' => $eur->id, 'amount' => 1000, 'currency' => 'EUR']);
    insightFlowDebit($user, ['account_id' => $hidden->id, 'amount' => 9000, 'currency' => 'USD']);

    [$from, $to] = insightFlowRange();

    expect(CountableFlow::total($user, TransactionDirection::Debit, $from, $to))->toBe(3000);
});

it('converts with stored rates only and never calls the rate provider', function () {
    Http::fake();
    config(['budget.multi_currency' => true, 'services.exchange_rates.enabled' => true]);
    $user = User::factory()->create(['base_currency' => 'USD']);
    $eur = Account::factory()->for($user)->create(['currency' => 'EUR']);
    insightFlowDebit($user, ['account_id' => $eur->id, 'amount' => 1000, 'currency' => 'EUR']);

    [$from, $to] = insightFlowRange();

    expect(CountableFlow::total($user, TransactionDirection::Debit, $from, $to))->toBe(1000);

    Http::assertNothingSent();
});
