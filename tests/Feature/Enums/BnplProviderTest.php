<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BnplProvider;

test('a bank narration names its provider', function (string $description, ?BnplProvider $provider) {
    expect(BnplProvider::fromBankDescription($description))->toBe($provider);
})->with([
    'afterpay card line' => ['VISA -Afterpay                 afterpay.com AU  145377 #8357', BnplProvider::Afterpay],
    'afterpay purchase' => ['AFTERPAY PURCHASE', BnplProvider::Afterpay],
    'paypal pay in 4' => ['VISA -PAYPAL *PYPL PAYIN4 4029357733 AU 845878 #8357', BnplProvider::Paypal],
    'ordinary merchant' => ['WOOLWORTHS 1234', null],
]);
