<?php

declare(strict_types=1);

use App\Support\Transactions\MerchantMatchValue;

it('extracts the merchant column and strips the VISA method prefix', function () {
    expect(MerchantMatchValue::for('VISA -POLYGON GROUP            TORRENSVILLE AU  624226 #7173'))
        ->toBe('POLYGON GROUP');
});

it('strips the Int Tran Fee prefix', function () {
    expect(MerchantMatchValue::for('Int Tran Fee - JetBrains              CZ - 923098'))
        ->toBe('JetBrains');
});

it('strips the VISA Android Pay prefix and clamps to 21 characters', function () {
    $value = MerchantMatchValue::for('VISA Android Pay-MOVE HEALTH SERVICES G   BRISBANE CITYAU  559816 #7173');

    expect($value)->toBe('MOVE HEALTH SERVICES ')
        ->and(mb_strlen($value))->toBe(21);
});

it('clamps to 21 chars so the 2026 and 2025 statement formats yield the same value', function () {
    $format2026 = MerchantMatchValue::for('VISA -CLAUDE.AI SUBSCRIPTION   ANTHROPIC.COMUS FRGN AMT-110.000000 061559 #7173');
    $format2025 = MerchantMatchValue::for('VISA -CLAUDE.AI SUBSCRIPTIO    ANTHROPIC.COMUS FRGN AMT-110.000000 061559 #7173');

    expect($format2026)->toBe('CLAUDE.AI SUBSCRIPTIO')
        ->and($format2025)->toBe($format2026);
});

it('returns null for a single-spaced description with no column structure', function () {
    expect(MerchantMatchValue::for('Transfer Optimus to CC from SAV 03745066 NET#2352024910'))
        ->toBeNull();
});

it('returns null when the merchant field is shorter than four characters', function () {
    expect(MerchantMatchValue::for('VISA -ABC            BRISBANE AU  624226 #7173'))
        ->toBeNull();
});
