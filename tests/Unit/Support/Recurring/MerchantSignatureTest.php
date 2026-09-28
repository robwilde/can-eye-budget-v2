<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Support\Recurring\MerchantSignature;

it('strips a trailing reference number', function () {
    expect(MerchantSignature::for('Direct Debit NIB - 64699390'))->toBe('DIRECT DEBIT NIB');
});

it('strips a varying bcx:int code', function () {
    expect(MerchantSignature::for('Direct Debit QBE Insurance - bcx:int 4821'))->toBe('DIRECT DEBIT QBE INSURANCE');
});

it('collapses Fair Go DT.xxxx variants to one signature', function () {
    $a = MerchantSignature::for('Direct Debit Fair Go Finance - DT.4y16g4 FGF 2472');
    $b = MerchantSignature::for('Direct Debit Fair Go Finance - DT.4yx8ph FGF 2472');

    expect($a)->toBe('DIRECT DEBIT FAIR GO FINANCE FGF')
        ->and($b)->toBe($a);
});

it('keeps two different Osko payees distinct', function () {
    $youUbank = MerchantSignature::for('Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885214478');
    $nikolai = MerchantSignature::for('Osko Payment To Nikolai Taylor Account 188722557 ANZ - Indooroo Ref#885376551');

    expect($youUbank)->not->toBe($nikolai)
        ->and($youUbank)->toContain('YOU')
        ->and($youUbank)->toContain('UBANK')
        ->and($nikolai)->toContain('NIKOLAI')
        ->and($nikolai)->toContain('TAYLOR');
});

it('drops the changing Ref# but keeps the payee for two instances of the same Osko payee', function () {
    $a = MerchantSignature::for('Osko Payment To 86400 Account 10386227 YOU - UBank Ref#885214478');
    $b = MerchantSignature::for('Osko Payment To 86400 Account 10386227 YOU - UBank Ref#999999999');

    expect($a)->toBe($b);
});

it('keeps merchant domains and drops the card mask and location code', function () {
    expect(MerchantSignature::for('VISA -Netflix.com   Melbourne    AU  724493 #2892'))
        ->toBe('NETFLIX.COM MELBOURNE AU');
});

it('preserves hyphenated payee words', function () {
    expect(MerchantSignature::for('Direct Debit TMR-Product Payt - 1056574545'))
        ->toBe('DIRECT DEBIT TMR-PRODUCT PAYT');
});

it('uppercases an already-clean merchant name unchanged', function () {
    expect(MerchantSignature::for('Netflix'))->toBe('NETFLIX');
});

it('keeps a merchant word that contains a boundary digit, e.g. 7-Eleven', function () {
    expect(MerchantSignature::for('VISA -7-Eleven 1234 Clayton VI AUS'))
        ->toBe('7-ELEVEN CLAYTON VI AUS');
});

it('falls back to the raw normalized string when every token is a code', function () {
    expect(MerchantSignature::for('Ref#884905699   2422732337'))->toBe('REF#884905699 2422732337');
});

it('collapses adjacent duplicate words and drops the digit-bearing code token', function () {
    expect(MerchantSignature::for('Direct Debit MCF - MCF Loa(N11590247)'))
        ->toBe('DIRECT DEBIT MCF');
});

it('preserves non-adjacent repeated words so distinct payees stay apart', function () {
    expect(MerchantSignature::for('Transfer Optimus to CC to SAV 03914373 NET#2422732337'))
        ->toBe('TRANSFER OPTIMUS TO CC TO SAV');
});

it('gives one key to a merchant however the bank marked the card use', function (array $descriptions, string $key) {
    foreach ($descriptions as $description) {
        expect(MerchantSignature::for($description))->toBe($key);
    }
})->with([
    'leading card network' => [[
        'VISA -Afterpay                 afterpay.com AU  090263 #8357',
        'Afterpay                 afterpay.com AU',
    ], 'AFTERPAY AFTERPAY.COM AU'],
    'trailing foreign marker' => [[
        'VISA -Patreon* Membership      Internet     IE FRGN AMT-5.000000 041234 #8357',
        'VISA -Patreon* Membership      Internet     IE  041234 #8357',
    ], 'PATREON MEMBERSHIP INTERNET IE'],
    'both, and neither' => [[
        'VISA -OPENAI *CHATGPT SUBSCR   OPENAI.COM   US FRGN AMT-20.000000 012345 #8357',
        'VISA -OPENAI *CHATGPT SUBSCR   OPENAI.COM   US  012345 #8357',
        'OPENAI *CHATGPT SUBSCR   OPENAI.COM   US',
    ], 'OPENAI CHATGPT SUBSCR OPENAI.COM US'],
    'other card networks' => [[
        'EFTPOS BAKER BROS NEWTOWN',
        'MASTERCARD BAKER BROS NEWTOWN',
        'BAKER BROS NEWTOWN',
    ], 'BAKER BROS NEWTOWN'],
]);

it('keeps a card token that is the only word, so the key is never empty', function (string $description, string $key) {
    expect(MerchantSignature::for($description))->toBe($key);
})->with([
    'network alone' => ['VISA', 'VISA'],
    'network plus codes' => ['VISA 724493 #8357', 'VISA'],
    'eftpos alone' => ['EFTPOS', 'EFTPOS'],
    'foreign marker alone' => ['FRGN', 'FRGN'],
    'network and foreign marker only' => ['VISA FRGN AMT-5.000000', 'FRGN'],
]);

it('drops card tokens only at the edges of the key', function (string $description, string $key) {
    expect(MerchantSignature::for($description))->toBe($key);
})->with([
    'network mid-key' => ['Round Up Transfer To XXXX4599 VISA Netflix.com Melbourne AU', 'ROUND UP TRANSFER TO XXXX4599 VISA NETFLIX.COM MELBOURNE AU'],
    'foreign marker mid-key' => ['ACME FRGN TRADING', 'ACME FRGN TRADING'],
    'MC is a name, not a network' => ['MC DONALDS FORTITUDE VALLEY', 'MC DONALDS FORTITUDE VALLEY'],
]);

it('keeps PayPal sub-merchants apart', function () {
    $steam = MerchantSignature::for('PAYPAL *STEAM 4829');
    $cloudns = MerchantSignature::for('PAYPAL *CLOUDNS');

    expect($steam)->toBe('PAYPAL STEAM')
        ->and($cloudns)->toBe('PAYPAL CLOUDNS')
        ->and(MerchantSignature::for('VISA -PAYPAL *STEAM 4829 #8357'))->toBe($steam);
});

// Stored merchant_brands keys are re-derived from the key itself on recompute,
// which is only sound when a key maps to itself.
it('maps a derived key to itself', function (string $description) {
    $key = MerchantSignature::for($description);

    expect(MerchantSignature::for($key))->toBe($key);
})->with([
    'VISA -ANTHROPIC* CLAUDE SUB    ANTHROPIC.COMUS FRGN AMT-220.000000 077040 #8357',
    'VISA EFTPOS WOOLWORTHS SYDNEY FRGN FRGN',
    'VISA FRGN',
    'Ref#884905699   2422732337',
]);

it('keeps the card marker in the redacted descriptor', function () {
    expect(MerchantSignature::redact('VISA -JetBrains   Prague   CZ FRGN AMT-1.320000 055718 #8357'))
        ->toBe('VISA JETBRAINS PRAGUE CZ FRGN')
        ->and(MerchantSignature::redact('Ref#884905699   2422732337'))->toBe('REF#884905699 2422732337');
});
