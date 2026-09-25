<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Transaction;
use App\Services\MerchantBrands\DescriptorGate;

/**
 * Pure: builds unsaved models, no database. A false allow sends payee text to a
 * third party, so the deny side of this table matters most.
 */
function gateTransaction(string $description, array $attributes = []): Transaction
{
    $transaction = new Transaction;
    $transaction->forceFill([
        'description' => $description,
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
        'clean_description' => null,
        ...$attributes,
    ]);

    return $transaction;
}

it('allows card-style merchant debits', function (string $descriptor) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor)))->toBeTrue();
})->with([
    'VISA WOOLWORTHS 1234 SYDNEY',
    'VISA Android Pay-WOOLWORTHS/111 BOUNDARY SWESTEND      AU  608180 #8357',
    'VISA -JetBrains                Prague       CZ FRGN AMT-1.320000 055718 #8357',
    'EFTPOS BAKER BROS NEWTOWN',
    'COLES SUPERMARKET MELBOURNE',
    'SQ *COFFEE SHOP CHATSWOOD AU',
    'COFFEE MINISTRY MALVERN EAST AU',
    'PAYPAL *STEAM 4829',
    'VISA -PAYPAL *PAYPROGLOBA      4029357733   CA  012007 #8357',
    'NETFLIX.COM',
    'SHELL COLES EXPRESS CAIRNS',
    'Direct Debit GOLDEN INSURANCE - PLCY 082212484-029',
    'UBER *TRIP HELP.UBER.COM',
]);

it('denies transfers, person payments, bank charges and income wording', function (string $descriptor) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor)))->toBeFalse();
})->with([
    'TRANSFER TO J SMITH',
    'Transfer From Transaction Acc HooliBank app',
    'INTERNET TRANSFER 123456 SAVINGS',
    'TFR TO JANE CITIZEN',
    'PAYMENT TO JOHN SMITH',
    'OSKO PAYMENT JANE CITIZEN',
    'PAYID JOHN@EXAMPLE.COM',
    'BPAY ORIGIN ENERGY 123456',
    'ATM WITHDRAWAL CBA SYDNEY',
    'CASH OUT WOOLWORTHS',
    'ACCOUNT FEE',
    'Access fee - Overseas ATMs',
    'INTEREST CHARGED',
    'HOME LOAN REPAYMENT',
    'CREDIT CARD PAYMENT',
    'PAYMENT  TO JOHN SMITH',
    'PAYMENT 123456 TO JOHN SMITH',
    'PAY  ANYONE JOHN SMITH',
    'INTERNET  BANKING JOHN SMITH',
    'TRF TO JOHN SMITH',
    'TRANSF TO JOHN SMITH',
    'PYMT JOHN SMITH RENT',
]);

it('denies payment-rail descriptors that name no merchant', function (string $descriptor) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor)))->toBeFalse();
})->with([
    'VISA -PAYPAL *PYPL PAYIN4      1800073263   AU  680866 #8357',
    'PAYPAL *PYPL PAYIN4      1800073263   AU',
    'VISA -Afterpay                 afterpay.com AU  341006 #8357',
    'Afterpay                 afterpay.com AU',
    'SQ *',
]);

it('denies unmarked descriptors shaped like a person\'s name', function (string $descriptor) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor)))->toBeFalse();
})->with([
    'JOHN SMITH',
    'J SMITH REF 20250614',
    'JANE A CITIZEN',
    "MARY O'BRIEN",
    'SMITH-JONES',
    'Direct Debit NIB - 64699390',
    // Plainly named merchants share the shape; refusing them is the accepted cost.
    'JB HI-FI HOBART',
    'WOOLWORTHS 1234 SYDNEY',
    'JOHN SMITH RENT JUNE',
    'DIRECT DEBIT JOHN SMITH',
    'MR JOHN ANDREW SMITH',
    'JOSÉ GARCÍA',
    'PAY JOHN SMITH',
    'DEBIT JOHN SMITH',
    'CARD JOHN SMITH',
]);

it('denies a PayPal payee that looks like a person, but not a PayPal merchant handle', function () {
    expect((new DescriptorGate)->allows(gateTransaction('PAYPAL *JOHN SMITH')))->toBeFalse()
        ->and((new DescriptorGate)->allows(gateTransaction('VISA -PAYPAL *JOHN SMITH      4029357733   AU  012007 #8357')))->toBeFalse()
        ->and((new DescriptorGate)->allows(gateTransaction('PAYPAL *JOHNSMITH')))->toBeTrue();
});

it('still allows a card-marked descriptor whose payee words look like a name', function () {
    // A card cannot pay a person directly, so the network prefix settles it.
    expect((new DescriptorGate)->allows(gateTransaction('VISA -HONEYMONEY.IO            EDMONTON     CA FRGN AMT-5.000000 078040 #8357')))->toBeTrue()
        ->and((new DescriptorGate)->allows(gateTransaction('EFTPOS JOHN SMITH')))->toBeTrue();
});

it('denies credits even when the wording looks like a merchant', function () {
    $refund = gateTransaction('WOOLWORTHS 1234 SYDNEY', ['direction' => TransactionDirection::Credit]);

    expect((new DescriptorGate)->allows($refund))->toBeFalse();
});

it('denies transfer pairs and folded fees regardless of wording', function (array $attributes) {
    expect((new DescriptorGate)->allows(gateTransaction('WOOLWORTHS 1234 SYDNEY', $attributes)))->toBeFalse();
})->with([
    'transfer pair' => [['transfer_pair_id' => 99]],
    'folded fee' => [['folded_into_transaction_id' => 99]],
]);

it('denies keys that name a payment rail rather than a merchant', function (string $descriptor) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor)))->toBeFalse();
})->with(['PAYPAL', 'SQ *', 'AFTERPAY', 'ZIP PAY']);

it('denies descriptors outside the API length bounds or with no merchant key', function (string $descriptor, array $attributes) {
    expect((new DescriptorGate)->allows(gateTransaction($descriptor, $attributes)))->toBeFalse();
})->with([
    'too short' => ['AB', []],
    'too long' => [str_repeat('WOOLWORTHS ', 50), []],
    'unknown merchant key' => ['WOOLWORTHS 1234 SYDNEY', ['merchant_key' => Transaction::UNKNOWN_MERCHANT_KEY]],
]);

it('sends a redacted descriptor, never card masks, auth codes or account numbers', function (string $raw, string $sent) {
    expect((new DescriptorGate)->sendableDescriptor(gateTransaction($raw)))->toBe($sent);
})->with([
    'card mask and auth code' => ['VISA -JetBrains                Prague       CZ FRGN AMT-1.320000 055718 #8357', 'VISA JETBRAINS PRAGUE CZ FRGN'],
    'policy number' => ['Direct Debit GOLDEN INSURANCE - PLCY 082212484-029', 'DIRECT DEBIT GOLDEN INSURANCE PLCY'],
    'member number' => ['Direct Debit NIB HEALTH INSURANCE - 64699390', 'DIRECT DEBIT NIB HEALTH INSURANCE'],
    'store number' => ['VISA WOOLWORTHS 1234 SYDNEY', 'VISA WOOLWORTHS SYDNEY'],
]);

it('refuses a descriptor whose redaction still carries a long number', function () {
    // MerchantSignature falls back to the raw text when every token is a code.
    expect((new DescriptorGate)->sendableDescriptor(gateTransaction('12345678 87654321', ['merchant_key' => 'SOME KEY'])))->toBeNull();
});
