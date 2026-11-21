<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementCoverageStatus;
use App\Models\Account;
use App\Models\AccountDailyBalance;
use App\Models\Statement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statement\StatementBalanceChecker;
use App\Services\Statement\StatementCoverageService;

beforeEach(function () {
    $this->travelTo('2026-04-15 10:00:00');
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['currency' => 'AUD']);
});

function coverageStatus(Account $account, int $month, int $year = 2026): StatementCoverageStatus
{
    return app(StatementCoverageService::class)->forAccount($account, $year)[$month - 1]->status;
}

function linkedStatement(Account $account, string $start, string $end, array $attributes = []): Statement
{
    return Statement::factory()->for($account->user)->create([
        'account_id' => $account->id,
        'period_start' => $start,
        'period_end' => $end,
    ] + $attributes);
}

function ledgerDay(Account $account, string $date, int $start, int $end, string $currency = 'AUD'): AccountDailyBalance
{
    return AccountDailyBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => $date,
        'start_balance' => $start,
        'end_balance' => $end,
        'currency' => $currency,
    ]);
}

it('marks a month covered by two overlapping linked statements as duplicate', function () {
    linkedStatement($this->account, '2026-03-01', '2026-03-31');
    linkedStatement($this->account, '2026-03-01', '2026-03-31');

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Duplicate);
});

it('treats two non-overlapping halves of a month as covered', function () {
    linkedStatement($this->account, '2026-03-01', '2026-03-15');
    linkedStatement($this->account, '2026-03-16', '2026-03-31');

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Covered);
});

it('marks months before the first activity as not expected and gaps as missing', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['post_date' => '2026-01-10']);
    linkedStatement($this->account, '2026-01-01', '2026-01-31');
    linkedStatement($this->account, '2026-03-01', '2026-03-31');

    $months = app(StatementCoverageService::class)->forAccount($this->account, 2026);

    expect($months[0]->status)->toBe(StatementCoverageStatus::Covered)
        ->and($months[1]->status)->toBe(StatementCoverageStatus::Missing)
        ->and($months[2]->status)->toBe(StatementCoverageStatus::Covered)
        ->and($months[3]->status)->toBe(StatementCoverageStatus::NotExpected)
        ->and(coverageStatus($this->account, 12, 2025))->toBe(StatementCoverageStatus::NotExpected);
});

it('marks a gap as missing when only a transaction shows activity', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['post_date' => '2026-01-10']);

    expect(coverageStatus($this->account, 2))->toBe(StatementCoverageStatus::Missing)
        ->and(coverageStatus($this->account, 12, 2025))->toBe(StatementCoverageStatus::NotExpected);
});

it('defaults to the last twelve months when the account has no activity', function () {
    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Missing)
        ->and(coverageStatus($this->account, 4))->toBe(StatementCoverageStatus::NotExpected)
        ->and(coverageStatus($this->account, 4, 2025))->toBe(StatementCoverageStatus::Missing)
        ->and(coverageStatus($this->account, 3, 2025))->toBe(StatementCoverageStatus::NotExpected);
});

it('starts the expected range at the first balance history row', function () {
    ledgerDay($this->account, '2026-02-20', 100, 100);

    expect(coverageStatus($this->account, 1))->toBe(StatementCoverageStatus::NotExpected)
        ->and(coverageStatus($this->account, 2))->toBe(StatementCoverageStatus::Missing);
});

it('marks a month ambiguous when only an unmatched suggested statement covers it', function () {
    Statement::factory()->for($this->user)->create([
        'suggested_account_id' => $this->account->id,
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
    ]);

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Ambiguous);
});

it('ignores statements suggested for another account, rejected ones and other users\' statements', function () {
    $other = Account::factory()->for($this->user)->create();
    Statement::factory()->for($this->user)->create(['suggested_account_id' => $other->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
    Statement::factory()->for($this->user)->rejected()->create(['suggested_account_id' => $this->account->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);
    Statement::factory()->create(['account_id' => $this->account->id, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31']);

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Missing);
});

it('counts a statement toward every month its period touches', function () {
    linkedStatement($this->account, '2026-01-20', '2026-03-05');

    expect(coverageStatus($this->account, 1))->toBe(StatementCoverageStatus::Covered)
        ->and(coverageStatus($this->account, 2))->toBe(StatementCoverageStatus::Covered)
        ->and(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Covered);
});

it('marks a month mismatched when the closing balance differs from the ledger', function () {
    linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 200000]);
    ledgerDay($this->account, '2026-03-31', 150000, 190000);

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Mismatched);
});

it('keeps a month covered when the balances agree with the ledger', function () {
    linkedStatement($this->account, '2026-03-01', '2026-03-31', ['opening_balance' => 150000, 'closing_balance' => 190000]);
    ledgerDay($this->account, '2026-03-01', 150000, 160000);
    ledgerDay($this->account, '2026-03-31', 185000, 190000);

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Covered);
});

it('skips balance checks when the ledger has no balance on the statement dates', function () {
    $statement = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 200000]);
    ledgerDay($this->account, '2026-03-30', 100, 100);

    $check = app(StatementBalanceChecker::class)->check($statement);

    expect($check->checked)->toBeFalse()
        ->and($check->isMismatch())->toBeFalse()
        ->and(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::Covered);
});

it('does not check a statement without an account, a period or in another currency', function () {
    $checker = app(StatementBalanceChecker::class);
    ledgerDay($this->account, '2026-03-31', 100, 190000, 'AUD');

    $unlinked = Statement::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'closing_balance' => 1]);
    $noPeriod = Statement::factory()->for($this->user)->create(['account_id' => $this->account->id, 'closing_balance' => 1]);
    $euro = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 1, 'currency' => 'EUR']);

    expect($checker->check($unlinked)->checked)->toBeFalse()
        ->and($checker->check($noPeriod)->checked)->toBeFalse()
        ->and($checker->check($euro)->checked)->toBeFalse();
});

it('tolerates a one cent difference but not two', function () {
    $checker = app(StatementBalanceChecker::class);
    ledgerDay($this->account, '2026-03-31', 100, 190000);

    $within = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 190001]);
    $beyond = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 190002]);

    expect($checker->check($within)->isMismatch())->toBeFalse()
        ->and($checker->check($beyond)->failures)->toBe(['closing']);
});

it('flags a movement that differs from the ledger', function () {
    ledgerDay($this->account, '2026-03-01', 100000, 100000);
    ledgerDay($this->account, '2026-03-31', 150000, 160000);
    $statement = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['opening_balance' => 100000, 'closing_balance' => 160000 + 500]);

    expect(app(StatementBalanceChecker::class)->check($statement)->failures)->toBe(['closing', 'movement']);
});

it('only reads the ledger of the statement\'s own account', function () {
    $other = Account::factory()->for($this->user)->create();
    ledgerDay($other, '2026-03-31', 100, 190000);
    $statement = linkedStatement($this->account, '2026-03-01', '2026-03-31', ['closing_balance' => 190000]);

    expect(app(StatementBalanceChecker::class)->check($statement)->checked)->toBeFalse();
});

it('shows nothing as missing for an account whose first activity is this month', function () {
    Transaction::factory()->for($this->user)->for($this->account)->create(['post_date' => '2026-04-10']);

    expect(coverageStatus($this->account, 3))->toBe(StatementCoverageStatus::NotExpected)
        ->and(coverageStatus($this->account, 4))->toBe(StatementCoverageStatus::NotExpected);
});

it('only balance checks statements that touch the requested year', function () {
    linkedStatement($this->account, '2025-03-01', '2025-03-31', ['closing_balance' => 200000]);
    ledgerDay($this->account, '2025-03-31', 100, 190000);

    expect(coverageStatus($this->account, 3, 2026))->toBe(StatementCoverageStatus::Missing)
        ->and(coverageStatus($this->account, 3, 2025))->toBe(StatementCoverageStatus::Mismatched);
});
