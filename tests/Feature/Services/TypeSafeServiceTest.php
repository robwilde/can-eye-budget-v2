<?php

declare(strict_types=1);

use App\Exceptions\TypeSafe\TypeSafeException;
use App\Services\TypeSafeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function typeSafeChoiceBody(): array
{
    return [
        'model' => 'jev-1.13.0',
        'answers' => ['answer' => [
            'type' => 'choice',
            'choice' => 'b',
            'probabilities' => ['a' => 0.2, 'b' => 0.7, 'c' => 0.1],
            'confidence' => 0.65,
        ]],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 4],
    ];
}

beforeEach(function () {
    Sleep::fake();
});

test('sends a single choice question and ranks the answer', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(typeSafeChoiceBody())]);

    $answer = (new TypeSafeService('ts_key'))->choose(['merchant_name' => 'ALDI'], 'Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C']);

    expect($answer->choice)->toBe('b')
        ->and($answer->ranked())->toBe(['b', 'a', 'c'])
        ->and($answer->margin())->toEqualWithDelta(0.5, 1e-9)
        ->and($answer->model)->toBe('jev-1.13.0')
        ->and($answer->inputTokens)->toBe(120);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer ts_key')
        && $request['model'] === 'jev-latest'
        && $request['state'] === ['merchant_name' => 'ALDI']
        && $request['questions']['answer']['type'] === 'choice'
        && $request['questions']['answer']['criteria'] === ['a' => 'A', 'b' => 'B', 'c' => 'C']);
});

test('retries a rate-limited or overloaded response with backoff', function (int $status) {
    Http::fake(['api.typesafe.ai/*' => Http::sequence()
        ->push(['error' => 'busy'], $status)
        ->push(typeSafeChoiceBody())]);

    $answer = (new TypeSafeService('ts_key'))->choose(['merchant_name' => 'ALDI'], 'Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C']);

    expect($answer->choice)->toBe('b');
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
})->with([429, 529]);

test('does not retry other client errors', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'bad'], 422)]);

    expect(fn () => (new TypeSafeService('ts_key'))->choose(['merchant_name' => 'ALDI'], 'Which?', ['a' => 'A', 'b' => 'B']))
        ->toThrow(TypeSafeException::class, 'HTTP 422');

    Http::assertSentCount(1);
});

test('rejects an answer whose choice is not one of the options', function () {
    $body = typeSafeChoiceBody();
    $body['answers']['answer']['choice'] = 'z';
    Http::fake(['api.typesafe.ai/*' => Http::response($body)]);

    expect(fn () => (new TypeSafeService('ts_key'))->choose(['merchant_name' => 'ALDI'], 'Which?', ['a' => 'A', 'b' => 'B', 'c' => 'C']))
        ->toThrow(TypeSafeException::class);
});
