<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\StatementReviewStatus;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use App\Services\Statement\StatementDetailsUpdater;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->updater = app(StatementDetailsUpdater::class);
});

it('saves the edited details', function () {
    $statement = Statement::factory()->for($this->user)->create();

    $this->updater->update($statement, [
        'institution_hint' => 'Westpac',
        'last_four_hint' => '1234',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'opening_balance' => 123456,
        'closing_balance' => -2000,
        'currency' => 'aud',
    ]);

    $fresh = $statement->fresh();

    expect($fresh->institution_hint)->toBe('Westpac')
        ->and($fresh->period_end?->toDateString())->toBe('2026-03-31')
        ->and($fresh->opening_balance)->toBe(123456)
        ->and($fresh->closing_balance)->toBe(-2000)
        ->and($fresh->currency)->toBe('AUD');
});

it('matches again when an unlinked statement gets new hints', function () {
    $account = Account::factory()->for($this->user)->create(['institution' => 'Westpac']);
    $statement = Statement::factory()->for($this->user)->create();

    $this->updater->update($statement, ['institution_hint' => 'Westpac']);

    expect($statement->fresh()->suggested_account_id)->toBe($account->id)
        ->and($statement->fresh()->account_id)->toBeNull();
});

it('drops a stale suggestion when the hints no longer match', function () {
    $account = Account::factory()->for($this->user)->create(['institution' => 'Westpac']);
    $statement = Statement::factory()->for($this->user)->create([
        'institution_hint' => 'Westpac',
        'suggested_account_id' => $account->id,
        'suggestion_confidence' => '0.3500',
    ]);

    $this->updater->update($statement, ['institution_hint' => 'Nothing']);

    expect($statement->fresh()->suggested_account_id)->toBeNull()
        ->and($statement->fresh()->suggestion_confidence)->toBeNull();
});

it('does not match a rejected statement again', function () {
    Account::factory()->for($this->user)->create(['institution' => 'Westpac']);
    $statement = Statement::factory()->for($this->user)->rejected()->create();

    $this->updater->update($statement, ['institution_hint' => 'Westpac']);

    expect($statement->fresh()->suggested_account_id)->toBeNull()
        ->and($statement->fresh()->review_status)->toBe(StatementReviewStatus::Rejected);
});

it('links to an own account and clears the suggestion', function () {
    $account = Account::factory()->for($this->user)->create();
    $statement = Statement::factory()->for($this->user)->create(['suggested_account_id' => $account->id, 'suggestion_confidence' => '0.5000']);

    $this->updater->update($statement, ['account_id' => $account->id]);

    expect($statement->fresh()->account_id)->toBe($account->id)
        ->and($statement->fresh()->review_status)->toBe(StatementReviewStatus::Linked)
        ->and($statement->fresh()->suggested_account_id)->toBeNull();
});

it('rejects invalid details and saves nothing', function (array $details, string $field) {
    $foreign = Account::factory()->for(User::factory()->create())->create();
    $statement = Statement::factory()->for($this->user)->create(['institution_hint' => 'Kept']);

    $details = array_map(fn ($value) => $value === 'FOREIGN' ? $foreign->id : $value, $details);

    expect(fn () => $this->updater->update($statement, $details + ['institution_hint' => 'Changed']))
        ->toThrow(ValidationException::class);

    try {
        $this->updater->update($statement, $details);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect($statement->fresh()->institution_hint)->toBe('Kept');
})->with([
    'period end before start' => [['period_start' => '2026-03-31', 'period_end' => '2026-03-01'], 'period_end'],
    'unknown currency' => [['currency' => 'XXX'], 'currency'],
    'malformed currency' => [['currency' => 'DOLLARS'], 'currency'],
    'another user\'s account' => [['account_id' => 'FOREIGN'], 'account_id'],
    'bad last four' => [['last_four_hint' => '12'], 'last_four_hint'],
]);

it('checks a partial period edit against the stored period', function (array $stored, array $details, string $field) {
    $statement = Statement::factory()->for($this->user)->create($stored);

    expect(fn () => $this->updater->update($statement, $details))->toThrow(ValidationException::class);

    try {
        $this->updater->update($statement, $details);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect($statement->fresh()->period_start?->toDateString())->toBe($stored['period_start'])
        ->and($statement->fresh()->period_end?->toDateString())->toBe($stored['period_end']);
})->with([
    'end before stored start' => [['period_start' => '2026-03-15', 'period_end' => '2026-03-31'], ['period_end' => '2026-03-01'], 'period_end'],
    'start after stored end' => [['period_start' => '2026-03-01', 'period_end' => '2026-03-31'], ['period_start' => '2026-04-01'], 'period_start'],
]);

it('saves a partial period edit that keeps the period ordered', function () {
    $statement = Statement::factory()->for($this->user)->create(['period_start' => '2026-03-01', 'period_end' => '2026-03-31']);

    $this->updater->update($statement, ['period_end' => '2026-04-15']);

    expect($statement->fresh()->period_start?->toDateString())->toBe('2026-03-01')
        ->and($statement->fresh()->period_end?->toDateString())->toBe('2026-04-15');
});
