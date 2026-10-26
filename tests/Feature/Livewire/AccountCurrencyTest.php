<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AccountClass;
use App\Enums\AccountGroup;
use App\Livewire\AccountManager;
use App\Livewire\ImportBank;
use App\Models\Account;
use App\Models\User;
use App\Services\Transfers\UntrackedAccountCreator;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function currencyCsvUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('StatementCsv-Westpac.csv', file_get_contents(base_path('tests/Fixtures/StatementCsv-Westpac.csv')));
}

function createAccountThrough(User $user, string $currency): Testable
{
    return Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Euro Saver')
        ->set('balance', '100')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->set('currency', $currency)
        ->call('save');
}

it('creates an account in a chosen allowed currency', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['AUD', 'EUR']])->create();

    createAccountThrough($user, 'EUR')->assertHasNoErrors()->assertSet('showFormModal', false);

    expect(Account::query()->where('user_id', $user->id)->sole()->currency)->toBe('EUR');
});

it('defaults a new account to the base currency', function () {
    $user = User::factory()->create(['base_currency' => 'NZD']);

    $modal = Livewire::actingAs($user)->test(AccountManager::class)->call('openAddModal');

    expect($modal->get('currency'))->toBe('NZD');
});

it('refuses to create an account in a currency that is not allowed', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['AUD', 'EUR']])->create();

    createAccountThrough($user, 'USD')->assertHasErrors(['currency']);

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('offers only allowed currencies in the account form', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    $modal = Livewire::actingAs($user)->test(AccountManager::class);

    expect($modal->viewData('currencies'))->toBe(['AUD', 'EUR']);
});

it('keeps the currency of an existing account when it is edited', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['AUD']])->create();
    $account = Account::factory()->for($user)->create(['currency' => 'EUR']);

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openEditModal', $account->id)
        ->assertSet('currency', 'EUR')
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->currency)->toBe('EUR')
        ->and($account->fresh()->name)->toBe('Renamed');
});

it('creates an untracked account in a chosen allowed currency', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddUntrackedModal')
        ->set('name', 'Euro vault')
        ->set('balance', '50')
        ->set('currency', 'EUR')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('user_id', $user->id)->sole())->currency->toBe('EUR')->is_tracked->toBeFalse();
});

it('creates an untracked account in the base currency by default', function () {
    $user = User::factory()->create(['base_currency' => 'USD']);

    expect(app(UntrackedAccountCreator::class)->create($user, 'Vault')->currency)->toBe('USD');
});

it('refuses an untracked account in a currency that is not allowed', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    expect(fn () => app(UntrackedAccountCreator::class)->create($user, 'Vault', currency: 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('creates a csv import account in a chosen allowed currency', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'Euro Bank')
        ->set('newAccountLast4', '1234')
        ->set('newAccountCurrency', 'EUR')
        ->set('file', currencyCsvUpload())
        ->call('uploadAndDetectHeaders')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    expect(Account::query()->where('user_id', $user->id)->sole()->currency)->toBe('EUR');
});

it('refuses a csv import account in a currency that is not allowed', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['EUR']])->create();

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'Dollar Bank')
        ->set('newAccountLast4', '1234')
        ->set('newAccountCurrency', 'USD')
        ->set('file', currencyCsvUpload())
        ->call('uploadAndDetectHeaders')
        ->assertHasErrors(['newAccountCurrency']);

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('defaults a csv import account to the base currency', function () {
    $user = User::factory()->create(['base_currency' => 'GBP']);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'Pound Bank')
        ->set('newAccountLast4', '1234')
        ->set('file', currencyCsvUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    expect(Account::query()->where('user_id', $user->id)->sole()->currency)->toBe('GBP');
});

it('does not let a forged editing id skip the allowed currency rule', function () {
    $user = User::factory()->withPreferences(['allowedCurrencies' => ['AUD']])->create();

    Livewire::actingAs($user)
        ->test(AccountManager::class)
        ->call('openAddModal')
        ->set('name', 'Forged')
        ->set('balance', '100')
        ->set('type', AccountClass::Transaction->value)
        ->set('group', AccountGroup::DayToDay->value)
        ->set('editingAccountId', 0)
        ->set('currency', 'XYZ')
        ->call('save')
        ->assertHasErrors(['currency']);

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('treats a whitespace currency on a csv import account as the base currency', function () {
    $user = User::factory()->create(['base_currency' => 'GBP']);

    Livewire::actingAs($user)
        ->test(ImportBank::class)
        ->set('accountChoice', 'new')
        ->set('newAccountName', 'Blank Bank')
        ->set('newAccountLast4', '1234')
        ->set('newAccountCurrency', '   ')
        ->set('file', currencyCsvUpload())
        ->call('uploadAndDetectHeaders')
        ->assertSet('step', 2);

    expect(Account::query()->where('user_id', $user->id)->sole()->currency)->toBe('GBP');
});
