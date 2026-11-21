<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountGroup;
use App\Models\Account;
use App\Models\Statement;
use App\Models\User;
use App\Services\Statement\StatementAccountMatcher;

function suggestionFor(Statement $statement): Statement
{
    app(StatementAccountMatcher::class)->suggest($statement);

    return $statement;
}

it('makes no suggestion on a tie or below the confidence threshold', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['institution' => 'CommBank', 'name' => 'Everyday', 'account_last4' => '1111', 'currency' => 'AUD']);
    Account::factory()->for($user)->create(['institution' => 'CommBank', 'name' => 'Savings', 'account_last4' => '2222', 'currency' => 'AUD']);

    $tie = suggestionFor(Statement::factory()->for($user)->make(['institution_hint' => 'CommBank']));
    $weak = suggestionFor(Statement::factory()->for($user)->make(['currency' => 'AUD']));

    expect($tie->suggested_account_id)->toBeNull()
        ->and($tie->suggestion_confidence)->toBeNull()
        ->and($weak->suggested_account_id)->toBeNull();
});

it('breaks what would be a tie with a distinguishing hint', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['institution' => 'CommBank', 'name' => 'Everyday', 'account_last4' => '1111']);
    $savings = Account::factory()->for($user)->create(['institution' => 'CommBank', 'name' => 'Savings', 'account_last4' => '2222']);

    $statement = suggestionFor(Statement::factory()->for($user)->make(['institution_hint' => 'CommBank', 'last_four_hint' => '2222']));

    expect($statement->suggested_account_id)->toBe($savings->id)
        ->and((float) $statement->suggestion_confidence)->toBe(0.75);
});

it('scores last four, name and currency together', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['institution' => 'Westpac', 'name' => 'Choice', 'account_last4' => '9876', 'currency' => 'AUD']);

    $statement = suggestionFor(Statement::factory()->for($user)->make([
        'institution_hint' => 'Westpac Banking',
        'account_name_hint' => 'Choice',
        'last_four_hint' => '9876',
        'currency' => 'AUD',
    ]));

    expect($statement->suggested_account_id)->toBe($account->id)
        ->and((float) $statement->suggestion_confidence)->toBe(1.0);
});

it('does not let currency alone suggest an account', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['currency' => 'AUD']);

    $statement = suggestionFor(Statement::factory()->for($user)->make(['currency' => 'AUD']));

    expect($statement->suggested_account_id)->toBeNull();
});

it('ignores hidden accounts and the account description', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['institution' => 'CommBank', 'group' => AccountGroup::Hidden]);
    Account::factory()->for($user)->create(['institution' => 'Other', 'name' => 'Plain', 'description' => 'CommBank joint']);

    $statement = suggestionFor(Statement::factory()->for($user)->make(['institution_hint' => 'CommBank']));

    expect($statement->suggested_account_id)->toBeNull();
});

it('never considers another user\'s accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for(User::factory()->create())->create(['institution' => 'CommBank', 'account_last4' => '4321']);

    $statement = suggestionFor(Statement::factory()->for($user)->make(['institution_hint' => 'CommBank', 'last_four_hint' => '4321']));

    expect($statement->suggested_account_id)->toBeNull();
});

it('does not match a short institution inside another word', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['institution' => 'ING', 'name' => 'Orange']);
    $westpac = Account::factory()->for($user)->create(['institution' => 'Westpac', 'name' => 'Choice']);

    $statement = suggestionFor(Statement::factory()->for($user)->make(['institution_hint' => 'Westpac Banking']));

    expect($statement->suggested_account_id)->toBe($westpac->id);
});

it('lets currency separate two otherwise equal accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['institution' => 'Wise', 'name' => 'Balance', 'account_last4' => '1234', 'currency' => 'EUR']);
    $usd = Account::factory()->for($user)->create(['institution' => 'Wise', 'name' => 'Balance', 'account_last4' => '1234', 'currency' => 'USD']);

    $statement = suggestionFor(Statement::factory()->for($user)->make([
        'institution_hint' => 'Wise',
        'account_name_hint' => 'Balance',
        'last_four_hint' => '1234',
        'currency' => 'USD',
    ]));

    expect($statement->suggested_account_id)->toBe($usd->id)
        ->and((float) $statement->suggestion_confidence)->toBe(1.0);
});
