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
    'WOOLWORTHS 1234 SYDNEY',
    'COLES SUPERMARKET MELBOURNE',
    'SQ *COFFEE SHOP CHATSWOOD AU',
    'COFFEE MINISTRY MALVERN EAST AU',
    'PAYPAL *STEAM 4829',
    'NETFLIX.COM',
    'SHELL COLES EXPRESS CAIRNS',
    'JB HI-FI HOBART',
    'DIRECT DEBIT TELSTRA 12345678',
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
]);

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
    'member number' => ['Direct Debit NIB - 64699390', 'DIRECT DEBIT NIB'],
    'store number' => ['WOOLWORTHS 1234 SYDNEY', 'WOOLWORTHS SYDNEY'],
]);

it('refuses a descriptor whose redaction still carries a long number', function () {
    // MerchantSignature falls back to the raw text when every token is a code.
    expect((new DescriptorGate)->sendableDescriptor(gateTransaction('12345678 87654321', ['merchant_key' => 'SOME KEY'])))->toBeNull();
});
