<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Rules\PaymentUrl;
use Illuminate\Support\Facades\Validator;

it('adds https to a link without a scheme', function (string $input, string $expected) {
    expect(PaymentUrl::normalise($input))->toBe($expected);
})->with([
    ['example.com/pay', 'https://example.com/pay'],
    ['  example.com  ', 'https://example.com'],
    ['//example.com/pay', 'https://example.com/pay'],
    ['example.com:8080/pay', 'https://example.com:8080/pay'],
    ['http://example.com', 'http://example.com'],
    ['HTTPS://example.com', 'HTTPS://example.com'],
]);

it('treats a blank link as none', function (?string $input) {
    expect(PaymentUrl::normalise($input))->toBeNull();
})->with([null, '', '   ']);

it('accepts only http and https links with a host', function (string $input, bool $valid) {
    $validator = Validator::make(['url' => $input], ['url' => [new PaymentUrl]]);

    expect($validator->passes())->toBe($valid);
})->with([
    'bare host' => ['example.com/pay', true],
    'http' => ['http://example.com/pay?a=1#x', true],
    'javascript' => ['javascript:alert(1)', false],
    'javascript mixed case' => ['JaVaScRiPt:alert(1)', false],
    'data' => ['data:text/html,<script>alert(1)</script>', false],
    'ftp' => ['ftp://example.com/file', false],
    'mailto' => ['mailto:a@example.com', false],
    'no host' => ['https://', false],
    'credentials' => ['https://user:pass@example.com', false],
    'whitespace inside' => ['https://exa mple.com', false],
    'control character' => ["https://example.com/\x01", false],
    'javascript with a digit' => ['javascript:1', false],
    'host with a port' => ['example.com:8080/pay', true],
    'localhost with a port' => ['localhost:8080/pay', true],
]);

it('refuses a link longer than 2048 characters', function () {
    $long = 'https://example.com/'.str_repeat('a', PaymentUrl::MAX_LENGTH);

    expect(Validator::make(['url' => $long], ['url' => [new PaymentUrl]])->passes())->toBeFalse()
        ->and(PaymentUrl::isSafe('https://example.com/'.str_repeat('a', 2000)))->toBeTrue()
        ->and(PaymentUrl::isSafe($long))->toBeFalse();
});

it('only treats a stored http or https link as safe to render', function (?string $input, bool $safe) {
    expect(PaymentUrl::isSafe($input))->toBe($safe);
})->with([
    [null, false],
    ['', false],
    ['https://example.com/pay', true],
    ['javascript:alert(1)', false],
    ['example.com/pay', false],
]);
