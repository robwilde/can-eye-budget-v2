<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\CleanDescriptionSource;
use App\Enums\PayFrequency;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\IncomePatternDetector;
use Carbon\CarbonImmutable;

/** @param  list<array{name: string, source: CleanDescriptionSource}|null>  $names */
function salaryCredits(User $user, Account $account, array $names): array
{
    $ids = [];

    foreach ($names as $index => $name) {
        $ids[] = Transaction::factory()->for($user)->for($account)->create([
            'description' => 'ACME PTY LTD PAYROLL',
            'amount' => 250_000,
            'direction' => TransactionDirection::Credit,
            'source' => TransactionSource::Redbark,
            'post_date' => CarbonImmutable::parse('2026-03-20')->subDays($index * 14),
            'merchant_name' => null,
            'clean_description' => $name['name'] ?? null,
            'clean_description_source' => $name['source'] ?? null,
            'transfer_pair_id' => null,
        ])->id;
    }

    return $ids;
}

test('import-derived clean names do not split identical raw salary descriptions', function () {
    $user = User::factory()->create();
    $named = Account::factory()->for($user)->create();
    $plain = Account::factory()->for($user)->create();

    $namedIds = salaryCredits($user, $named, [
        ['name' => 'Acme Pay', 'source' => CleanDescriptionSource::Feed],
        null,
        ['name' => 'Acme Salary', 'source' => CleanDescriptionSource::Derived],
        null,
    ]);
    $plainIds = salaryCredits($user, $plain, [null, null, null, null]);

    $withNames = app(IncomePatternDetector::class)->detectForAccount($named);
    $withoutNames = app(IncomePatternDetector::class)->detectForAccount($plain);

    expect($withNames)->not->toBeNull()
        ->and($withoutNames)->not->toBeNull()
        ->and($withNames->transactionIds)->toEqualCanonicalizing($namedIds)
        ->and($withoutNames->transactionIds)->toEqualCanonicalizing($plainIds)
        ->and($withNames->frequency)->toBe(PayFrequency::Fortnightly)
        ->and($withNames->frequency)->toBe($withoutNames->frequency);
});
