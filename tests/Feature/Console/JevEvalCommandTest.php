<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CategorySource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['services.typesafe.api_key' => 'ts_test']);
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();

    $this->food = Category::factory()->create(['name' => 'Food', 'parent_id' => null]);
    $this->groceries = Category::factory()->create(['name' => 'Groceries', 'parent_id' => $this->food->id]);
    $this->dining = Category::factory()->create(['name' => 'Dining', 'parent_id' => $this->food->id]);
    $this->transport = Category::factory()->create(['name' => 'Transport', 'parent_id' => null]);
});

/** A manually categorised merchant debit. */
function jevTruth(object $test, string $merchant, Category $category, string $postDate, array $overrides = []): Transaction
{
    return Transaction::factory()->for($test->user)->for($test->account)->debit()->create([
        'description' => 'CARD 4455 '.$merchant.' REF 998877',
        'merchant_name' => $merchant,
        'amount' => 12345,
        'post_date' => $postDate,
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
        ...$overrides,
    ]);
}

/**
 * Fake Jev: answer each question with the option on the path to $answers[merchant_name]
 * (a category full path), putting 0.8 on it and spreading the rest.
 *
 * @param  array<string, string>  $answers  masked merchant name => full path to answer with
 */
function fakeJev(array $answers): void
{
    Http::fake(function (Request $request) use ($answers) {
        $target = $answers[$request['state']['merchant_name']] ?? '';
        $criteria = $request['questions']['answer']['criteria'];

        $choice = array_key_first($criteria);
        foreach ($criteria as $id => $path) {
            if ($target === $path || str_starts_with($target, $path.' / ')) {
                $choice = $id;
            }
        }

        $others = count($criteria) - 1;
        $probabilities = array_map(fn (string $id): float => $id === $choice ? 0.8 : 0.2 / max(1, $others), array_combine(array_keys($criteria), array_keys($criteria)));

        return Http::response([
            'model' => 'jev-1.13.0',
            'answers' => ['answer' => ['type' => 'choice', 'choice' => $choice, 'probabilities' => $probabilities, 'confidence' => 0.7]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 3],
        ]);
    });
}

/** @return list<array<string, mixed>> the state of every request sent */
function sentStates(): array
{
    return Http::recorded()->map(fn (array $pair): array => $pair[0]['state'])->values()->all();
}

test('fails with a clear error when TYPESAFE_API_KEY is not configured', function () {
    config(['services.typesafe.api_key' => null]);
    Http::fake();
    jevTruth($this, 'ALDI', $this->groceries, '2026-06-01');

    $this->artisan('categories:jev-eval')
        ->expectsOutputToContain('TYPESAFE_API_KEY is not configured.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('sends only the digit-masked merchant name, never description, amount or date', function () {
    fakeJev([]);
    jevTruth($this, 'WOOLWORTHS 1234 METRO 56', $this->groceries, '2026-06-01');

    $this->artisan('categories:jev-eval')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request['state'] === ['merchant_name' => 'WOOLWORTHS # METRO #']);
    Http::recorded()->each(function (array $pair) {
        $body = $pair[0]->body();
        expect($body)->not->toContain('1234')
            ->not->toContain('CARD')
            ->not->toContain('998877')
            ->not->toContain('12345')
            ->not->toContain('2026')
            ->not->toContain('description')
            ->not->toContain('amount')
            ->not->toContain('date')
            ->not->toContain('user');
    });
});

test('excludes credits and transfers, and asks once per merchant_key', function () {
    fakeJev(['KILO' => 'Transport', 'HOTEL' => 'Transport']);
    $older = jevTruth($this, 'OLDER', $this->groceries, '2026-01-01');
    foreach (['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA', 'ECHO', 'FOXTROT', 'GOLF', 'HOTEL'] as $i => $merchant) {
        jevTruth($this, $merchant, $this->transport, '2026-02-0'.($i + 1));
    }
    // Two rows for the newest merchant: still one question per variant.
    jevTruth($this, 'KILO', $this->transport, '2026-03-01');
    jevTruth($this, 'KILO', $this->transport, '2026-03-02');
    // Newer than everything, so either would be held out if it were eligible.
    jevTruth($this, 'REFUNDCO', $this->transport, '2026-05-01', ['direction' => 'credit']);
    jevTruth($this, 'SAVINGS', $this->transport, '2026-05-02', ['transfer_pair_id' => $older->id]);

    $this->artisan('categories:jev-eval')->assertSuccessful();

    // 10 eligible merchants -> 2 held out; Transport has no children -> 1 call per variant.
    $names = array_column(sentStates(), 'merchant_name');
    expect($names)->toHaveCount(4)
        ->and(array_count_values($names))->toBe(['KILO' => 2, 'HOTEL' => 2]);
});

test('adds the feed category and MCC as an unverified hint only in the hinted variant', function () {
    fakeJev([]);
    jevTruth($this, 'ALDI', $this->groceries, '2026-06-01', [
        'enrich_data' => ['redbark' => ['category' => 'groceries', 'merchantCategoryCode' => '5411']],
    ]);

    $this->artisan('categories:jev-eval')->assertSuccessful();

    $hinted = array_values(array_filter(sentStates(), fn (array $state): bool => isset($state['unverified_hint'])));
    expect(sentStates())->toHaveCount(4)
        ->and($hinted)->toHaveCount(2)
        ->and($hinted[0]['unverified_hint'])->toBe(['bank_category' => 'groceries', 'mcc' => '5411']);
});

test('reports accuracy on a held-out fixture without touching transactions', function () {
    // 10 merchants; the newest two are held out.
    foreach (range(1, 8) as $day) {
        jevTruth($this, 'OLD'.chr(64 + $day), $this->transport, "2026-01-0{$day}");
    }
    jevTruth($this, 'ALDI', $this->groceries, '2026-06-01');
    jevTruth($this, 'SUSHI TRAIN', $this->dining, '2026-06-02');

    fakeJev([
        'ALDI' => 'Food / Groceries',       // right
        'SUSHI TRAIN' => 'Food / Groceries', // right hub, wrong leaf
    ]);

    $before = Transaction::query()->orderBy('id')->get()->toArray();

    $this->artisan('categories:jev-eval')
        ->expectsOutputToContain('Model: jev-1.13.0')
        ->expectsOutputToContain('Held-out merchants: 2')
        ->expectsTable(['Metric', 'no hint', 'with hint'], [
            ['Top-level accuracy', '100.0% (2/2)', '100.0% (2/2)'],
            ['Top-1 accuracy', '50.0% (1/2)', '50.0% (1/2)'],
            ['Top-3 accuracy', '100.0% (2/2)', '100.0% (2/2)'],
        ])
        ->expectsOutputToContain('Usage: 8 calls, 800 input tokens, 24 output tokens')
        ->assertSuccessful();

    expect(Transaction::query()->orderBy('id')->get()->toArray())->toBe($before);
});
