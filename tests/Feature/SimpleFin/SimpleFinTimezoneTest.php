<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\RedbarkTransport;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

uses()->group('simplefin');

/** @return list<string> the post dates the feed's SimpleFIN client reads from a transaction posted at $epoch */
function simpleFinPostDates(User $user, int $epoch): array
{
    Http::preventStrayRequests();
    Http::fake(['*/simplefin/accounts*' => Http::response([
        'errors' => [],
        'accounts' => [[
            'id' => 'acct-1',
            'name' => 'Everyday',
            'currency' => 'AUD',
            'balance' => '10.00',
            'transactions' => [['id' => 'tx-1', 'posted' => $epoch, 'amount' => '-5.00', 'description' => 'Coffee']],
        ]],
    ])]);

    $feed = RedbarkFeed::factory()->for($user)->create([
        'transport' => RedbarkTransport::SimpleFin,
        'api_key' => null,
        'simplefin_access_url' => 'https://sfuser:sfpass@api.redbark.com/simplefin',
    ]);

    $transactions = app(RedbarkClientFactory::class)->for($feed)->getTransactions(
        'simplefin',
        'acct-1',
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-04-30'),
    );

    return array_map(static fn ($transaction): string => (string) $transaction->postDate, $transactions);
}

test('a SimpleFIN transaction posts on the date in the owning user timezone', function () {
    useAppTimezone('UTC');
    $epoch = CarbonImmutable::parse('2026-03-31 14:30:00', 'UTC')->getTimestamp();

    expect(simpleFinPostDates(User::factory()->withTimezone('Australia/Sydney')->create(), $epoch))->toBe(['2026-04-01'])
        ->and(simpleFinPostDates(User::factory()->create(), $epoch))->toBe(['2026-03-31']);
});
