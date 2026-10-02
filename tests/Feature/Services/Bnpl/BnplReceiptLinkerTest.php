<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\RawEmail;
use App\Models\Account;
use App\Models\BnplOrder;
use App\Models\BnplOrderEvent;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\TransactionEmail;
use App\Models\User;
use App\Services\Bnpl\BnplLinkResult;
use App\Services\Bnpl\BnplOrderImporter;
use App\Services\Bnpl\BnplReceiptLinker;
use App\Support\Email\Schedules\PayPalReceiptStrategy;
use Carbon\CarbonImmutable;

const PAYIN4_DESCRIPTION = 'VISA -PAYPAL *PYPL PAYIN4 4029357733 AU 845878 #8357';

function linkPayPalReceipt(BnplOrder $order, RawEmail $email): BnplLinkResult
{
    return app(BnplReceiptLinker::class)->link($order, $email, (new PayPalReceiptStrategy)->parse($email));
}

function importPayPalOrder(User $user, RawEmail $email): BnplOrder
{
    return app(BnplOrderImporter::class)->import($user, $email, (new PayPalReceiptStrategy)->parse($email));
}

/** @param array<string, mixed> $attributes */
function payIn4Debit(Account $account, string $postDate, array $attributes = []): Transaction
{
    return Transaction::factory()->for($account->user)->for($account)->debit()->create([
        'amount' => -5025,
        'description' => PAYIN4_DESCRIPTION,
        'post_date' => $postDate,
        ...$attributes,
    ]);
}

function paymentLinkedEvents(): int
{
    return BnplOrderEvent::query()->where('event', 'payment_linked')->count();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-06 04:00'));
    $this->user = User::factory()->create();
    $this->card = Account::factory()->for($this->user)->creditCard()->create();
    $this->order = importPayPalOrder($this->user, payPalReceiptEmail());
    $this->receipt = payPalInstalmentReceiptEmail(2);
});

test('a receipt attaches to its posting and links it to the order plan', function () {
    $debit = payIn4Debit($this->card, '2026-08-05');

    $result = linkPayPalReceipt($this->order, $this->receipt);

    $email = TransactionEmail::query()->sole();
    $event = $this->order->events()->where('event', 'payment_linked')->sole();

    expect($result->transaction?->id)->toBe($debit->id)
        ->and($result->ambiguous)->toBeFalse()
        ->and($email->transaction_id)->toBe($debit->id)
        ->and($email->user_id)->toBe($this->user->id)
        ->and($email->gmail_message_id)->toBe('paypal-receipt-2@mail.test')
        ->and($email->subject)->toBe('Your PayPal Pay in 4 payment went through')
        ->and($email->details['loanReference'])->toBe('eacfa072-30dc-40eb-a93d-acc70b06d4d2')
        ->and($debit->fresh()->planned_transaction_id)->toBe($this->order->planned_transaction_id)
        ->and($event->payload)->toBe([
            'transaction_id' => $debit->id,
            'gmail_message_id' => 'paypal-receipt-2@mail.test',
            'amount' => 5025,
            'posted_on' => '2026-08-04',
            'planned_transaction_id' => $this->order->planned_transaction_id,
            'relinked_from_plan_id' => null,
            'unlinked_transaction_ids' => [],
        ]);
});

test('a posting tolerance-linked to another plan is moved to the order plan', function () {
    $other = PlannedTransaction::factory()->for($this->user)->for($this->card)->create();
    $debit = payIn4Debit($this->card, '2026-08-05', ['planned_transaction_id' => $other->id]);

    linkPayPalReceipt($this->order, $this->receipt);

    expect($debit->fresh()->planned_transaction_id)->toBe($this->order->planned_transaction_id)
        ->and($this->order->events()->where('event', 'payment_linked')->sole()->payload['relinked_from_plan_id'])->toBe($other->id);
});

test('another posting sitting on the same plan occurrence is released', function () {
    $guess = Transaction::factory()->for($this->user)->for($this->card)->debit()->create([
        'amount' => -5000, 'description' => 'SOME OTHER MERCHANT', 'post_date' => '2026-08-04',
        'planned_transaction_id' => $this->order->planned_transaction_id,
    ]);
    $earlier = Transaction::factory()->for($this->user)->for($this->card)->debit()->create([
        'amount' => -5025, 'description' => PAYIN4_DESCRIPTION, 'post_date' => '2026-07-22',
        'planned_transaction_id' => $this->order->planned_transaction_id,
    ]);
    payIn4Debit($this->card, '2026-08-05');

    linkPayPalReceipt($this->order, $this->receipt);

    expect($guess->fresh()->planned_transaction_id)->toBeNull()
        ->and($earlier->fresh()->planned_transaction_id)->toBe($this->order->planned_transaction_id)
        ->and($this->order->events()->where('event', 'payment_linked')->sole()->payload['unlinked_transaction_ids'])->toBe([$guess->id]);
});

test('postings that do not match the receipt exactly are left alone', function (array $attributes) {
    $debit = payIn4Debit($this->card, '2026-08-05', $attributes);

    $result = linkPayPalReceipt($this->order, $this->receipt);

    expect($result->transaction)->toBeNull()
        ->and($result->ambiguous)->toBeFalse()
        ->and(TransactionEmail::query()->count())->toBe(0)
        ->and($debit->fresh()->planned_transaction_id)->toBeNull()
        ->and(paymentLinkedEvents())->toBe(0);
})->with([
    'a pending hold' => [['status' => App\Enums\TransactionStatus::Pending]],
    'one cent more' => [['amount' => -5026]],
    'four days after Posted on' => [['post_date' => '2026-08-08']],
    'not a PayPal instalment' => [['description' => 'VISA -UMART ONLINE']],
    'a credit' => [['direction' => App\Enums\TransactionDirection::Credit]],
]);

test('an unsigned debit amount still matches', function () {
    $debit = payIn4Debit($this->card, '2026-08-05', ['amount' => 5025]);

    expect(linkPayPalReceipt($this->order, $this->receipt)->transaction?->id)->toBe($debit->id);
});

test('two postings equally close to the receipt are ambiguous and nothing is linked', function () {
    $a = payIn4Debit($this->card, '2026-08-05');
    $b = payIn4Debit($this->card, '2026-08-03');

    $result = linkPayPalReceipt($this->order, $this->receipt);

    expect($result->transaction)->toBeNull()
        ->and($result->ambiguous)->toBeTrue()
        ->and(TransactionEmail::query()->count())->toBe(0)
        ->and($a->fresh()->planned_transaction_id)->toBeNull()
        ->and($b->fresh()->planned_transaction_id)->toBeNull()
        ->and(paymentLinkedEvents())->toBe(0);
});

test('the posting nearest the Posted-on date wins', function () {
    $far = payIn4Debit($this->card, '2026-08-07');
    $near = payIn4Debit($this->card, '2026-08-05');

    expect(linkPayPalReceipt($this->order, $this->receipt)->transaction?->id)->toBe($near->id)
        ->and($far->fresh()->planned_transaction_id)->toBeNull();
});

test('a posting already carrying another PayPal receipt is not given a second one', function () {
    $taken = payIn4Debit($this->card, '2026-08-05');
    TransactionEmail::factory()->for($this->user)->for($taken)->create([
        'gmail_message_id' => 'another-paypal-receipt@mail.test',
        'from_address' => 'service@paypal.com.au',
    ]);
    $free = payIn4Debit($this->card, '2026-08-07');

    expect(linkPayPalReceipt($this->order, $this->receipt)->transaction?->id)->toBe($free->id);
});

test('linking the same receipt again changes nothing', function () {
    payIn4Debit($this->card, '2026-08-05');

    linkPayPalReceipt($this->order, $this->receipt);
    $again = linkPayPalReceipt($this->order, $this->receipt);

    expect($again->transaction)->toBeNull()
        ->and(TransactionEmail::query()->count())->toBe(1)
        ->and(paymentLinkedEvents())->toBe(1);
});

test('a settled order still attaches its receipts but links no plan', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-03 04:00'));
    $settled = importPayPalOrder($this->user, payPalReceiptEmail(
        messageId: 'settled-loan-receipt@mail.test',
        replace: ['eacfa072-30dc-40eb-a93d-acc70b06d4d2' => '5b0d7a43-2f1e-4c39-9a51-0c3e8f6a2d10'],
    ));
    $receipt = payPalInstalmentReceiptEmail(3, 'settled-loan-receipt-3@mail.test');
    $debit = payIn4Debit($this->card, '2026-08-19');

    $result = linkPayPalReceipt($settled, $receipt);

    expect($settled->planned_transaction_id)->toBeNull()
        ->and($result->transaction?->id)->toBe($debit->id)
        ->and(TransactionEmail::query()->sole()->transaction_id)->toBe($debit->id)
        ->and($debit->fresh()->planned_transaction_id)->toBeNull()
        ->and($settled->events()->where('event', 'payment_linked')->sole()->payload['planned_transaction_id'])->toBeNull();
});
