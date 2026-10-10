<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\PayeeReviewItem;
use App\Enums\BudgetTag;
use App\Enums\PayeeStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\MerchantBrand;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payees\PayeeReviewQueue;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();

    $this->eatingOut = Category::factory()->create(['name' => 'Eating Out', 'parent_id' => null, 'budget_tag' => BudgetTag::Wants]);
    $this->quickFoods = Category::factory()->create(['name' => 'Quick Foods', 'parent_id' => $this->eatingOut->id]);
});

/** A pending payee Jev was unsure about, with its debits. */
function queuePayee(object $test, string $name, array $payee = [], array $amounts = [1000], ?User $owner = null): Payee
{
    $owner ??= $test->user;
    $account = $owner->is($test->user) ? $test->account : Account::factory()->for($owner)->create();

    $key = (new Transaction(['merchant_name' => $name]))->resolveMerchantKey();

    foreach ($amounts as $amount) {
        Transaction::factory()->for($owner)->for($account)->debit()->create([
            'description' => $name.'  REF '.$amount,
            'merchant_name' => $name,
            'amount' => $amount,
            'post_date' => CarbonImmutable::parse('2026-09-15'),
            'category_id' => null,
            'category_source' => null,
        ]);
    }

    return Payee::factory()->create([
        'user_id' => $owner->id,
        'merchant_key' => $key,
        'merchant_name' => $name,
        'suggested_category_id' => $test->quickFoods->id,
        'confidence' => 0.5,
        'top_to_second' => 1.0,
        ...$payee,
    ]);
}

test('payees are ordered by monthly spend, counting how often they are paid', function () {
    $small = queuePayee($this, 'SMALL CAFE', amounts: [5000]);
    $often = queuePayee($this, 'OFTEN CAFE', amounts: [3000, 3000, 3000]);
    $big = queuePayee($this, 'BIG CAFE', amounts: [7000]);

    $items = app(PayeeReviewQueue::class)->pending($this->user);

    expect($items->pluck('payeeId')->all())->toBe([$often->id, $big->id, $small->id])
        ->and($items->pluck('monthlySpend')->all())->toBe([9000, 7000, 5000]);
});

test('equal spend is ordered by how many other pending payees share the brand industry', function () {
    $alone = queuePayee($this, 'ALONE CAFE', amounts: [4000]);
    $shared = queuePayee($this, 'SHARED CAFE', amounts: [4000]);
    $sibling = queuePayee($this, 'SIBLING CAFE', amounts: [100]);

    foreach ([$shared, $sibling] as $payee) {
        MerchantBrand::factory()->create(['user_id' => $this->user->id, 'merchant_key' => $payee->merchant_key, 'industry' => 'Food', 'subindustry' => 'Cafes']);
    }

    $ids = app(PayeeReviewQueue::class)->pending($this->user)->pluck('payeeId')->all();

    expect($ids)->toBe([$shared->id, $alone->id, $sibling->id]);
});

test('only payees where review matters are queued', function () {
    $lowConfidence = queuePayee($this, 'LOW CAFE', ['confidence' => 0.6, 'top_to_second' => 9.0]);
    $closeCall = queuePayee($this, 'CLOSE CAFE', ['confidence' => 0.95, 'top_to_second' => 1.4]);
    $noSuggestion = queuePayee($this, 'NONE CAFE', ['suggested_category_id' => null, 'confidence' => null, 'top_to_second' => null]);
    $ambiguous = queuePayee($this, 'APPLE.COM/BILL', ['confidence' => 0.95, 'top_to_second' => 19.0]);
    queuePayee($this, 'SURE CAFE', ['confidence' => 0.95, 'top_to_second' => 19.0]);

    $items = app(PayeeReviewQueue::class)->pending($this->user);

    expect($items->pluck('payeeId')->sort()->values()->all())->toBe(collect([$lowConfidence, $closeCall, $noSuggestion, $ambiguous])->pluck('id')->sort()->values()->all())
        ->and($items->firstWhere('payeeId', $ambiguous->id)->isAmbiguous)->toBeTrue()
        ->and($items->firstWhere('payeeId', $lowConfidence->id)->isAmbiguous)->toBeFalse();
});

test('a name that merely contains a keyword is not ambiguous', function () {
    $payee = queuePayee($this, 'PINEAPPLE BAR');

    $item = app(PayeeReviewQueue::class)->pending($this->user)->firstWhere('payeeId', $payee->id);

    expect($item->isAmbiguous)->toBeFalse();
});

test('answered payees and other users payees are never queued', function () {
    queuePayee($this, 'CONFIRMED CAFE', ['status' => PayeeStatus::Confirmed]);
    queuePayee($this, 'DISMISSED CAFE', ['status' => PayeeStatus::Dismissed]);
    $other = User::factory()->create();
    queuePayee($this, 'THEIR CAFE', owner: $other);
    $mine = queuePayee($this, 'MY CAFE');

    $items = app(PayeeReviewQueue::class)->pending($this->user);

    expect($items->pluck('payeeId')->all())->toBe([$mine->id]);
});

test("another user's transactions do not count toward a payee's figures", function () {
    $mine = queuePayee($this, 'SHARED NAME', amounts: [2000]);
    $other = User::factory()->create();
    queuePayee($this, 'SHARED NAME', amounts: [9000, 9000], owner: $other);

    $item = app(PayeeReviewQueue::class)->pending($this->user)->sole();

    expect($item->payeeId)->toBe($mine->id)
        ->and($item->transactionCount)->toBe(1)
        ->and($item->typicalAmount)->toBe(2000);
});

test('an item carries what the review step shows', function () {
    $payee = queuePayee($this, 'BREW HOUSE', amounts: [1000, 2000, 6000]);

    foreach (['a', 'b', 'c', 'd'] as $suffix) {
        Transaction::factory()->for($this->user)->for($this->account)->debit()->create([
            'description' => 'BREW HOUSE  STORE '.$suffix,
            'merchant_name' => 'BREW HOUSE',
            'amount' => 2000,
            'post_date' => CarbonImmutable::parse('2026-09-15'),
            'category_id' => null,
            'category_source' => null,
        ]);
    }

    $item = app(PayeeReviewQueue::class)->pending($this->user)->sole();

    expect($item)->toBeInstanceOf(PayeeReviewItem::class)
        ->and($item->payeeId)->toBe($payee->id)
        ->and($item->merchantName)->toBe('BREW HOUSE')
        ->and($item->suggestedCategoryId)->toBe($this->quickFoods->id)
        ->and($item->suggestedTag)->toBe(BudgetTag::Wants)
        ->and($item->transactionCount)->toBe(7)
        ->and($item->typicalAmount)->toBe(2000)
        ->and($item->rawDescriptions)->toHaveCount(3)
        ->and(count(array_unique($item->rawDescriptions)))->toBe(3);
});

test('the suggested tag follows the payee override and is null without a suggestion', function () {
    $overridden = queuePayee($this, 'OVERRIDDEN CAFE', ['budget_tag' => BudgetTag::Needs]);
    $none = queuePayee($this, 'NONE CAFE', ['suggested_category_id' => null]);

    $items = app(PayeeReviewQueue::class)->pending($this->user);

    expect($items->firstWhere('payeeId', $overridden->id)->suggestedTag)->toBe(BudgetTag::Needs)
        ->and($items->firstWhere('payeeId', $none->id)->suggestedTag)->toBeNull();
});

test('the limit caps the queue after ordering', function () {
    queuePayee($this, 'ONE CAFE', amounts: [1000]);
    $top = queuePayee($this, 'TWO CAFE', amounts: [9000]);
    queuePayee($this, 'THREE CAFE', amounts: [2000]);

    $items = app(PayeeReviewQueue::class)->pending($this->user, limit: 2);

    expect($items)->toHaveCount(2)
        ->and($items->first()->payeeId)->toBe($top->id);
});

test('an empty queue is an empty collection', function () {
    queuePayee($this, 'SURE CAFE', ['confidence' => 0.95, 'top_to_second' => 19.0]);

    expect(app(PayeeReviewQueue::class)->pending($this->user))->toBeEmpty();
});

test('two payments one month apart count as one payment a month', function () {
    $payee = queuePayee($this, 'MONTHLY CAFE', amounts: [3000]);

    Transaction::factory()->for($this->user)->for($this->account)->debit()->create([
        'description' => 'MONTHLY CAFE  REF EARLIER',
        'merchant_name' => 'MONTHLY CAFE',
        'amount' => 3000,
        'post_date' => CarbonImmutable::parse('2026-08-15'),
        'category_id' => null,
        'category_source' => null,
    ]);

    $item = app(PayeeReviewQueue::class)->pending($this->user)->firstWhere('payeeId', $payee->id);

    expect($item->monthlySpend)->toBe(3000);
});

test('a null limit returns every payee worth asking about', function () {
    foreach (['ALPHA', 'BRAVO', 'CHARLIE'] as $name) {
        queuePayee($this, $name.' CAFE');
    }

    expect(app(PayeeReviewQueue::class)->pending($this->user, 2))->toHaveCount(2)
        ->and(app(PayeeReviewQueue::class)->pending($this->user, null))->toHaveCount(3);
});

test('the ambiguity predicate answers for one merchant name without building the queue', function () {
    $queue = app(PayeeReviewQueue::class);

    expect($queue->isAmbiguous('APPLE.COM/BILL'))->toBeTrue()
        ->and($queue->isAmbiguous('FRESH GROCER'))->toBeFalse();
});
