<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\RedbarkTransactionMatcher;
use Carbon\CarbonImmutable;

function matcher(): RedbarkTransactionMatcher
{
    return new RedbarkTransactionMatcher;
}

/** @param  array<string, mixed>  $overrides */
function csvTransaction(Account $account, array $overrides = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'amount' => -4250,
        'direction' => TransactionDirection::Debit,
        'source' => TransactionSource::Csv,
        'post_date' => '2026-08-10',
        'description' => 'WOOLWORTHS 1234 BONDI',
        'redbark_id' => null,
        ...$overrides,
    ]);
}

test('the fingerprint survives the digit masking a bank feed applies', function (string $a, string $b) {
    expect(RedbarkTransactionMatcher::fingerprint($a))
        ->toBe(RedbarkTransactionMatcher::fingerprint($b));
})->with([
    'masked account number' => ['Direct Debit NIB - xxxx9390', 'Direct Debit NIB - 64699390'],
    'long mask' => ['Direct Debit GO 060726 - xxxxxxxxxxxxxx6636', 'Direct Debit GO 060726 - 005218933538256636'],
    'transfer reference' => [
        'Ext Tfr  - NET#5018734695 to xxxxx1715 Liang Ma NAB',
        'Ext Tfr  - NET#5018734695 to 982291715 Liang Ma NAB',
    ],
    'case only' => ['Direct Credit WINABLE PAYROLL', 'DIRECT CREDIT WINABLE PAYROLL'],
    'whitespace' => ['VISA -Afterpay    afterpay.com AU', 'VISA -Afterpay afterpay.com AU'],
]);

test('descriptions that are genuinely different do not share a fingerprint', function () {
    expect(RedbarkTransactionMatcher::fingerprint('WOOLWORTHS BONDI'))
        ->not->toBe(RedbarkTransactionMatcher::fingerprint('COLES BONDI'));
});

test('an existing CSV transaction is found despite the masked description', function () {
    $account = Account::factory()->create();
    $existing = csvTransaction($account, ['description' => 'Direct Debit NIB - 64699390']);

    $found = matcher()->findExisting(
        $account->id,
        -4250,
        CarbonImmutable::parse('2026-08-10'),
        'Direct Debit NIB - xxxx9390',
    );

    expect($found?->id)->toBe($existing->id);
});

test('a statement date up to three days off still matches, nearest first', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['post_date' => '2026-08-05']);
    $nearest = csvTransaction($account, ['post_date' => '2026-08-09']);

    $found = matcher()->findExisting(
        $account->id,
        -4250,
        CarbonImmutable::parse('2026-08-10'),
        'WOOLWORTHS 1234 BONDI',
    );

    expect($found?->id)->toBe($nearest->id);
});

test('a date beyond the tolerance is not matched', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['post_date' => '2026-08-01']);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI'))
        ->toBeNull();
});

test('a different amount is never matched, however similar the description', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['amount' => -4251]);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI'))
        ->toBeNull();
});

test('a same-amount transaction with an unrelated description is left alone', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['description' => 'BUNNINGS WAREHOUSE']);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI'))
        ->toBeNull();
});

test('another account never matches', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    csvTransaction($other);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI'))
        ->toBeNull();
});

test('a row already owned by the feed is not re-matched', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['redbark_id' => 'bank_tx_existing', 'source' => TransactionSource::Redbark]);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI'))
        ->toBeNull();
});

test('already-claimed transactions are excluded so matching is one to one', function () {
    $account = Account::factory()->create();
    $first = csvTransaction($account);
    $second = csvTransaction($account);

    $found = matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI', [$first->id]);

    expect($found?->id)->toBe($second->id);
});

test('a folded fee is found rather than treated as new', function () {
    $account = Account::factory()->create();
    $parent = csvTransaction($account, ['amount' => -10000, 'description' => 'VISA -JETBRAINS']);

    $fee = csvTransaction($account, [
        'amount' => -191,
        'description' => 'Int Tran Fee - JetBrains CZ - 955718',
        'folded_into_transaction_id' => $parent->id,
    ]);
    $fee->delete();

    $found = matcher()->findExisting(
        $account->id,
        -191,
        CarbonImmutable::parse('2026-08-10'),
        'Int Tran Fee - JetBrains CZ - 955718',
    );

    expect($found?->id)->toBe($fee->id)
        ->and($found?->trashed())->toBeTrue()
        ->and($found?->folded_into_transaction_id)->toBe($parent->id);
});

test('the current version is preferred over the one it superseded', function () {
    $account = Account::factory()->create();
    $original = csvTransaction($account);
    $current = $original->createChild(['description' => 'WOOLWORTHS 1234 BONDI']);

    $found = matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI');

    expect($found?->id)->toBe($current->id);
});

test('a superseded row is still matched when its newer version lives elsewhere', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create(['user_id' => $account->user_id]);

    // Version chains can cross accounts. Excluding superseded rows outright would leave
    // this one permanently unmatchable, and the feed would import a duplicate every sync.
    $original = csvTransaction($account);
    $original->createChild(['account_id' => $other->id]);

    $found = matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), 'WOOLWORTHS 1234 BONDI');

    expect($found?->id)->toBe($original->id);
});

test('a blank description never matches anything', function () {
    $account = Account::factory()->create();
    csvTransaction($account, ['description' => '']);

    expect(matcher()->findExisting($account->id, -4250, CarbonImmutable::parse('2026-08-10'), ''))
        ->toBeNull();
});
