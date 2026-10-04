<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CleanDescriptionSource;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\RecurringTransactionDetector;
use App\Services\RuleActionExecutor;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

/** @param  array<string, mixed>  $attributes */
function namedTransaction(User $user, Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'source' => TransactionSource::Redbark,
        'description' => 'VISA ACME HOSTING 1234',
        'merchant_name' => null,
        'clean_description' => null,
        'category_id' => null,
        ...$attributes,
    ]);
}

test('a name is saved with a source and a blank name clears it', function () {
    $transaction = namedTransaction($this->user, $this->account, ['clean_description' => 'Acme hosting']);

    expect($transaction->clean_description_source)->toBe(CleanDescriptionSource::Manual);

    $transaction->update(['clean_description' => '  ']);

    expect($transaction->fresh()->clean_description_source)->toBeNull();
});

test('an import-derived name never feeds the merchant key but a deliberate one does', function (CleanDescriptionSource $source, string $expectedKey) {
    $transaction = namedTransaction($this->user, $this->account, [
        'clean_description' => 'Hosting bill',
        'clean_description_source' => $source,
    ]);

    expect($transaction->merchant_key)->toBe($expectedKey);
})->with([
    'manual' => [CleanDescriptionSource::Manual, 'HOSTING BILL'],
    'rule' => [CleanDescriptionSource::Rule, 'HOSTING BILL'],
    'feed' => [CleanDescriptionSource::Feed, 'ACME HOSTING'],
    'brand' => [CleanDescriptionSource::Brand, 'ACME HOSTING'],
    'derived' => [CleanDescriptionSource::Derived, 'ACME HOSTING'],
]);

test('an offered name yields only to a source of equal or higher rank', function (?CleanDescriptionSource $current, CleanDescriptionSource $offered, bool $taken) {
    $transaction = namedTransaction($this->user, $this->account, $current === null ? [] : [
        'clean_description' => 'Current',
        'clean_description_source' => $current,
    ]);

    expect($transaction->offerCleanDescription('Offered', $offered))->toBe($taken)
        ->and($transaction->clean_description)->toBe($taken ? 'Offered' : ($current === null ? null : 'Current'));
})->with([
    'blank takes derived' => [null, CleanDescriptionSource::Derived, true],
    'derived takes brand' => [CleanDescriptionSource::Derived, CleanDescriptionSource::Brand, true],
    'brand takes feed' => [CleanDescriptionSource::Brand, CleanDescriptionSource::Feed, true],
    'feed takes feed' => [CleanDescriptionSource::Feed, CleanDescriptionSource::Feed, true],
    'feed takes rule' => [CleanDescriptionSource::Feed, CleanDescriptionSource::Rule, true],
    'brand refuses derived' => [CleanDescriptionSource::Brand, CleanDescriptionSource::Derived, false],
    'feed refuses brand' => [CleanDescriptionSource::Feed, CleanDescriptionSource::Brand, false],
    'rule refuses feed' => [CleanDescriptionSource::Rule, CleanDescriptionSource::Feed, false],
    'manual refuses rule' => [CleanDescriptionSource::Manual, CleanDescriptionSource::Rule, false],
    'manual refuses feed' => [CleanDescriptionSource::Manual, CleanDescriptionSource::Feed, false],
]);

test('a blank offer is never taken', function () {
    $transaction = namedTransaction($this->user, $this->account);

    expect($transaction->offerCleanDescription('   ', CleanDescriptionSource::Feed))->toBeFalse()
        ->and($transaction->clean_description)->toBeNull();
});

test('a pipeline rule renames an import-derived name but not a deliberate one', function (CleanDescriptionSource $current, bool $renamed) {
    $transaction = namedTransaction($this->user, $this->account, [
        'clean_description' => 'Current',
        'clean_description_source' => $current,
    ]);

    app(RuleActionExecutor::class)->execute($transaction, [
        ['type' => 'set_clean_description', 'value' => 'Acme hosting'],
    ], overwriteCleanDescription: false);

    $fresh = $transaction->fresh();

    expect($fresh->clean_description)->toBe($renamed ? 'Acme hosting' : 'Current')
        ->and($fresh->clean_description_source)->toBe($renamed ? CleanDescriptionSource::Rule : $current);
})->with([
    'feed' => [CleanDescriptionSource::Feed, true],
    'brand' => [CleanDescriptionSource::Brand, true],
    'derived' => [CleanDescriptionSource::Derived, true],
    'rule' => [CleanDescriptionSource::Rule, false],
    'manual' => [CleanDescriptionSource::Manual, false],
]);

test('a person-triggered rule overwrites any name and stamps it as a rule name', function () {
    $transaction = namedTransaction($this->user, $this->account, ['clean_description' => 'Mine']);

    app(RuleActionExecutor::class)->execute($transaction, [
        ['type' => 'set_clean_description', 'value' => 'Acme hosting'],
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->clean_description)->toBe('Acme hosting')
        ->and($fresh->clean_description_source)->toBe(CleanDescriptionSource::Rule);
});

test('recurring detection groups rows the same whether or not a brand has named some of them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-20'));

    $rows = collect(['2026-01-15', '2026-02-15', '2026-03-15'])->map(fn (string $date): Transaction => namedTransaction($this->user, $this->account, [
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'amount' => -1699,
        'direction' => TransactionDirection::Debit,
        'post_date' => CarbonImmutable::parse($date),
    ]));
    $detector = app(RecurringTransactionDetector::class);
    $before = $detector->detectFrom($rows);

    $rows->first()->offerCleanDescription('Woolworths Group', CleanDescriptionSource::Brand);
    $rows->first()->save();
    $after = $detector->detectFrom($rows->map->fresh());

    expect($before)->toHaveCount(1)
        ->and($after)->toHaveCount(1)
        ->and($after->first()->description)->toBe($before->first()->description)
        ->and($after->first()->matchedTransactionIds)->toHaveCount(3);
});
