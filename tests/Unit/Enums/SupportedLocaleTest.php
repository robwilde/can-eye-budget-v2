<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\SupportedLocale;

it('picks the first supported language from an Accept-Language header', function (?string $header, ?SupportedLocale $expected) {
    expect(SupportedLocale::fromAcceptLanguage($header))->toBe($expected);
})->with([
    'german' => ['de-DE,de;q=0.9,en;q=0.8', SupportedLocale::German],
    'region of a supported language' => ['en-AU', SupportedLocale::English],
    'skips unsupported languages' => ['fr-FR,fr;q=0.9,de;q=0.5', SupportedLocale::German],
    'highest quality wins over order' => ['en;q=0.4,de;q=0.9', SupportedLocale::German],
    'a zero quality language is refused' => ['de;q=0,fr', null],
    'a malformed quality refuses that language' => ['de;q=abc,en;q=0.9', SupportedLocale::English],
    'a negative quality refuses that language' => ['de;q=-1,en;q=0.9', SupportedLocale::English],
    'a quality above one refuses that language' => ['de;q=2,en;q=0.9', SupportedLocale::English],
    'only unsupported languages' => ['fr-FR,ja', null],
    'wildcard only' => ['*', null],
    'empty header' => ['   ', null],
    'no header' => [null, null],
]);
