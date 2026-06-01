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
        ->toBe('VISA NETFLIX.COM MELBOURNE AU');
});

it('preserves hyphenated payee words', function () {
    expect(MerchantSignature::for('Direct Debit TMR-Product Payt - 1056574545'))
        ->toBe('DIRECT DEBIT TMR-PRODUCT PAYT');
});

it('uppercases an already-clean merchant name unchanged', function () {
    expect(MerchantSignature::for('Netflix'))->toBe('NETFLIX');
});

it('returns an empty signature when every token is a code', function () {
    expect(MerchantSignature::for('Ref#884905699 2422732337'))->toBe('');
});

it('collapses adjacent duplicate words and drops the digit-bearing code token', function () {
    expect(MerchantSignature::for('Direct Debit MCF - MCF Loa(N11590247)'))
        ->toBe('DIRECT DEBIT MCF');
});

it('preserves non-adjacent repeated words so distinct payees stay apart', function () {
    expect(MerchantSignature::for('Transfer Optimus to CC to SAV 03914373 NET#2422732337'))
        ->toBe('TRANSFER OPTIMUS TO CC TO SAV');
});
