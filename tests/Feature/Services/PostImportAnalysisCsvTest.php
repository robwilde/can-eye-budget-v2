<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\PayFrequency;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionAnalysisPipeline;
use Carbon\CarbonImmutable;

/**
 * Regression coverage for issue #249: a clean CSV import produced no primary
 * account, pay cycle, or recurring suggestions because the analysis stages
 * filtered on source = Basiq and CSV rows were written as source = Csv.
 *
 * @param  array<string, mixed>  $overrides
 */
function importedCsvTransaction(User $user, Account $account, array $overrides): Transaction
{
    return Transaction::factory()
        ->for($user)
        ->for($account)
        ->fromCsv()
        ->create(array_merge(['transfer_pair_id' => null], $overrides));
}

test('a clean CSV import auto-sets the primary account and pay cycle and surfaces recurring suggestions', function () {
    $user = User::factory()->create([
        'primary_account_id' => null,
        'pay_amount' => null,
        'pay_frequency' => null,
        'next_pay_date' => null,
    ]);
    $account = Account::factory()->for($user)->create();

    // Fortnightly salary credits imported from CSV (the primary-account signal).
    $lastPay = CarbonImmutable::today()->subDays(4);
    for ($i = 0; $i < 6; $i++) {
        importedCsvTransaction($user, $account, [
            'description' => 'ACME PAYROLL',
            'amount' => 320_000,
            'direction' => TransactionDirection::Credit,
            'post_date' => $lastPay->subDays(14 * (5 - $i)),
        ]);
    }

    // A recurring monthly debit imported from CSV.
    $firstDebit = CarbonImmutable::today()->subMonths(3);
    for ($i = 0; $i < 3; $i++) {
        importedCsvTransaction($user, $account, [
            'description' => 'NETFLIX.COM',
            'amount' => 1699,
            'direction' => TransactionDirection::Debit,
            'post_date' => $firstDebit->addMonthsNoOverflow($i),
        ]);
    }

    $run = app(TransactionAnalysisPipeline::class)->run($user, PipelineTrigger::Sync);

    expect($run->status)->toBe(PipelineRunStatus::Completed);

    $user->refresh();

    // Primary account and pay cycle were auto-applied from the CSV data.
    expect($user->primary_account_id)->toBe($account->id)
        ->and($user->pay_frequency)->toBe(PayFrequency::Fortnightly)
        ->and($user->pay_amount)->toBe(320_000)
        ->and($user->next_pay_date)->not->toBeNull();

    // The recurring debit was surfaced as a suggestion for review.
    $netflix = AnalysisSuggestion::query()
        ->where('user_id', $user->id)
        ->where('type', SuggestionType::RecurringTransaction)
        ->get()
        ->first(fn (AnalysisSuggestion $s): bool => $s->payload['description'] === 'NETFLIX.COM');

    expect($netflix)->not->toBeNull();
});
