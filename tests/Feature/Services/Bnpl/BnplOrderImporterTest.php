<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\ParsedSchedule;
use App\DTOs\RawEmail;
use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Bnpl\BnplOrderImporter;
use App\Support\Email\Schedules\PayPalReceiptStrategy;
use Carbon\CarbonImmutable;

function importReceipt(User $user, RawEmail $email): BnplOrder
{
    $schedule = (new PayPalReceiptStrategy)->parse($email);

    expect($schedule)->toBeInstanceOf(ParsedSchedule::class);

    return app(BnplOrderImporter::class)->import($user, $email, $schedule);
}

/** @return list<string> */
function orderEvents(BnplOrder $order): array
{
    return $order->events()->orderBy('id')->pluck('event')->map->value->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-20 19:00'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->user->update(['primary_account_id' => $this->account->id]);
});

test('the first receipt of a loan creates the order and one fortnightly plan', function () {
    $order = importReceipt($this->user, payPalReceiptEmail());
    $plan = $order->plannedTransaction;

    expect($order->provider)->toBe(BnplProvider::Paypal)
        ->and($order->order_ref)->toBe('eacfa072-30dc-40eb-a93d-acc70b06d4d2')
        ->and($order->retailer)->toBe('Umart Online')
        ->and($order->total)->toBe(20099)
        ->and($order->instalment_amount)->toBe(5025)
        ->and($order->instalment_count)->toBe(4)
        ->and($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->review_note)->toBe(BnplOrder::REVIEW_NOTE_NO_CATEGORY)
        ->and($order->account_id)->toBe($this->account->id)
        ->and($order->gmail_message_id)->toBe('paypal-receipt-first@mail.test')
        ->and($order->parsed_payload['instalments'])->toHaveCount(4)
        ->and(orderEvents($order))->toBe(['email_pulled', 'review_requested', 'plan_created'])
        ->and($plan)->toBeInstanceOf(PlannedTransaction::class)
        ->and($plan->user_id)->toBe($this->user->id)
        ->and($plan->account_id)->toBe($this->account->id)
        ->and($plan->category_id)->toBeNull()
        ->and($plan->amount)->toBe(5025)
        ->and($plan->direction)->toBe(TransactionDirection::Debit)
        ->and($plan->description)->toBe('PayPal Pay in 4 - Umart Online')
        ->and($plan->frequency)->toBe(RecurrenceFrequency::Every2Weeks)
        ->and($plan->start_date->toDateString())->toBe('2026-07-21')
        ->and($plan->until_date->toDateString())->toBe('2026-09-01')
        ->and($plan->occurrencesBetween(CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-09-30'))
            ->map->toDateString()->all())->toBe(['2026-07-21', '2026-08-04', '2026-08-18', '2026-09-01']);
});

test('a later receipt for a known loan creates nothing', function () {
    $first = importReceipt($this->user, payPalReceiptEmail());

    $again = importReceipt($this->user, payPalInstalmentReceiptEmail(2));

    expect($again->id)->toBe($first->id)
        ->and(BnplOrder::query()->count())->toBe(1)
        ->and(PlannedTransaction::query()->count())->toBe(1)
        ->and($again->events()->count())->toBe(3);
});

test('the same message under another loan reference creates nothing', function () {
    $first = importReceipt($this->user, payPalReceiptEmail());

    $again = importReceipt($this->user, payPalReceiptEmail(
        replace: ['eacfa072-30dc-40eb-a93d-acc70b06d4d2' => '5b0d7a43-2f1e-4c39-9a51-0c3e8f6a2d10'],
    ));

    expect($again->id)->toBe($first->id)
        ->and(BnplOrder::query()->count())->toBe(1)
        ->and(PlannedTransaction::query()->count())->toBe(1);
});

test('a seller with a remembered category is auto-approved and its plan inherits the category', function () {
    $older = Category::factory()->create();
    $latest = Category::factory()->create();
    BnplOrder::factory()->for($this->user)->settled()->create([
        'provider' => BnplProvider::Paypal, 'retailer' => 'Umart Online', 'category_id' => $older->id, 'reviewed_at' => now()->subMonths(2),
    ]);
    BnplOrder::factory()->for($this->user)->settled()->create([
        'provider' => BnplProvider::Paypal, 'retailer' => 'Umart Online', 'category_id' => $latest->id, 'reviewed_at' => now()->subMonth(),
    ]);
    BnplOrder::factory()->for($this->user)->settled()->create([
        'provider' => BnplProvider::Paypal, 'retailer' => 'Another Shop', 'category_id' => $older->id, 'reviewed_at' => now(),
    ]);

    $order = importReceipt($this->user, payPalReceiptEmail());

    expect($order->status)->toBe(BnplOrderStatus::AutoApproved)
        ->and($order->review_note)->toBeNull()
        ->and($order->reviewed_at)->not->toBeNull()
        ->and($order->category_id)->toBe($latest->id)
        ->and($order->plannedTransaction->category_id)->toBe($latest->id)
        ->and(orderEvents($order))->toBe(['email_pulled', 'auto_approved', 'plan_created']);
});

test('a schedule with no supported cadence is kept for review without a plan', function () {
    $order = importReceipt($this->user, payPalReceiptEmail(replace: ['18 August 2026' => '19 August 2026']));

    expect($order->frequency)->toBeNull()
        ->and($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->review_note)->toBe(BnplOrder::REVIEW_NOTE_UNSUPPORTED_CADENCE)
        ->and($order->planned_transaction_id)->toBeNull()
        ->and(PlannedTransaction::query()->count())->toBe(0)
        ->and(orderEvents($order))->toBe(['email_pulled', 'review_requested']);
});

test('a settled schedule is remembered without a plan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00'));

    $order = importReceipt($this->user, payPalReceiptEmail());

    expect($order->exists)->toBeTrue()
        ->and($order->planned_transaction_id)->toBeNull()
        ->and(PlannedTransaction::query()->count())->toBe(0)
        ->and(orderEvents($order))->toBe(['email_pulled', 'review_requested']);
});

test('the plan sits on the account PayPal instalments post to, not the primary account', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    Transaction::factory()->for($this->user)->for($card)->debit()->create([
        'description' => 'VISA -PAYPAL *PYPL PAYIN4 4029357733 AU #8357',
        'post_date' => '2026-07-01',
    ]);

    $order = importReceipt($this->user, payPalReceiptEmail());

    expect($order->account_id)->toBe($card->id)
        ->and($order->plannedTransaction->account_id)->toBe($card->id);
});

test('a previous PayPal order account wins over the bank-description match', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    $chosen = Account::factory()->for($this->user)->create();
    Transaction::factory()->for($this->user)->for($card)->debit()->create([
        'description' => 'VISA -PAYPAL *PYPL PAYIN4 4029357733 AU #8357',
        'post_date' => '2026-07-01',
    ]);
    BnplOrder::factory()->for($this->user)->settled()->create([
        'provider' => BnplProvider::Paypal, 'account_id' => $chosen->id,
    ]);

    expect(importReceipt($this->user, payPalReceiptEmail())->account_id)->toBe($chosen->id);
});

test('the newest PayPal order account wins even before that order is reviewed', function () {
    $old = Account::factory()->for($this->user)->create();
    $new = Account::factory()->for($this->user)->creditCard()->create();
    BnplOrder::factory()->for($this->user)->settled()->approved()->create([
        'provider' => BnplProvider::Paypal, 'account_id' => $old->id,
    ]);
    BnplOrder::factory()->for($this->user)->create([
        'provider' => BnplProvider::Paypal, 'account_id' => $new->id,
    ]);

    expect(importReceipt($this->user, payPalReceiptEmail())->account_id)->toBe($new->id);
});

test('a later PayPal credit does not move plans off the account instalments are charged to', function () {
    $card = Account::factory()->for($this->user)->creditCard()->create();
    $refunds = Account::factory()->for($this->user)->create();
    Transaction::factory()->for($this->user)->for($card)->debit()->create([
        'description' => 'VISA -PAYPAL *PYPL PAYIN4 4029357733 AU #8357',
        'post_date' => '2026-07-01',
    ]);
    Transaction::factory()->for($this->user)->for($refunds)->credit()->create([
        'description' => 'PAYPAL *PYPL PAYIN4 REFUND',
        'post_date' => '2026-07-10',
    ]);

    expect(importReceipt($this->user, payPalReceiptEmail())->account_id)->toBe($card->id);
});

test('setting the plan category approves the order and seeds the next order for that seller', function () {
    $category = Category::factory()->create();
    $order = importReceipt($this->user, payPalReceiptEmail());

    $order->plannedTransaction->update(['category_id' => $category->id]);
    $order->refresh();

    expect($order->category_id)->toBe($category->id)
        ->and($order->status)->toBe(BnplOrderStatus::Approved)
        ->and($order->review_note)->toBeNull()
        ->and($order->reviewed_at)->not->toBeNull()
        ->and(orderEvents($order))->toBe(['email_pulled', 'review_requested', 'plan_created', 'category_set', 'approved'])
        ->and($order->events()->where('event', 'category_set')->value('payload'))
        ->toBe(['category_id' => $category->id, 'previous_category_id' => null]);

    $next = importReceipt($this->user, payPalReceiptEmail(
        messageId: 'paypal-receipt-next-order@mail.test',
        replace: ['eacfa072-30dc-40eb-a93d-acc70b06d4d2' => '5b0d7a43-2f1e-4c39-9a51-0c3e8f6a2d10'],
    ));

    expect($next->status)->toBe(BnplOrderStatus::AutoApproved)
        ->and($next->category_id)->toBe($category->id);
});

test('clearing the category of an approved plan sends its order back to review', function () {
    $category = Category::factory()->create();
    $order = importReceipt($this->user, payPalReceiptEmail());
    $order->plannedTransaction->update(['category_id' => $category->id]);

    $order->plannedTransaction->update(['category_id' => null]);
    $order->refresh();

    expect($order->category_id)->toBeNull()
        ->and($order->status)->toBe(BnplOrderStatus::PendingReview)
        ->and($order->review_note)->toBe(BnplOrder::REVIEW_NOTE_NO_CATEGORY)
        ->and($order->reviewed_at)->toBeNull()
        ->and(orderEvents($order))->toBe([
            'email_pulled', 'review_requested', 'plan_created', 'category_set', 'approved', 'category_set', 'review_requested',
        ]);
});

test('changing the category of an unrelated plan leaves BNPL orders alone', function () {
    $order = importReceipt($this->user, payPalReceiptEmail());
    $other = PlannedTransaction::factory()->for($this->user)->for($this->account)->create();

    $other->update(['category_id' => Category::factory()->create()->id]);

    expect($order->fresh()->category_id)->toBeNull()
        ->and($order->events()->count())->toBe(3);
});
