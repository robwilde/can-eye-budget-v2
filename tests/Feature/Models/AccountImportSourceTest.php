<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;

test('import_source defaults to manual', function () {
    $account = Account::factory()->create();

    expect($account->import_source)->toBe(ImportSource::Manual);
});

test('import_source is cast to enum', function () {
    $account = Account::factory()->create(['import_source' => 'csv']);

    expect($account->import_source)->toBe(ImportSource::Csv);
});

test('column_mapping is cast to array', function () {
    $mapping = ['date' => 'Entered Date', 'amount' => 'Amount'];
    $account = Account::factory()->create(['column_mapping' => $mapping]);

    expect($account->column_mapping)->toBe($mapping);
});

test('csvImport scope only includes csv accounts', function () {
    $user = User::factory()->create();
    $csv = Account::factory()->for($user)->create(['import_source' => ImportSource::Csv]);
    Account::factory()->for($user)->create(['import_source' => ImportSource::Manual]);
    Account::factory()->for($user)->create(['import_source' => ImportSource::Basiq]);

    $results = Account::query()->csvImport()->where('user_id', $user->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($csv->id);
});

test('basiqConnected scope only includes basiq accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['import_source' => ImportSource::Csv]);
    $basiq = Account::factory()->for($user)->create(['import_source' => ImportSource::Basiq]);

    $results = Account::query()->basiqConnected()->where('user_id', $user->id)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($basiq->id);
});

test('isImportSource matches the configured source', function () {
    $account = Account::factory()->create(['import_source' => ImportSource::Csv]);

    expect($account->isImportSource(ImportSource::Csv))->toBeTrue()
        ->and($account->isImportSource(ImportSource::Basiq))->toBeFalse();
});

test('acceptsCsvImports is true for csv and manual accounts', function () {
    $csv = Account::factory()->create(['import_source' => ImportSource::Csv]);
    $manual = Account::factory()->create(['import_source' => ImportSource::Manual]);
    $basiq = Account::factory()->create(['import_source' => ImportSource::Basiq]);

    expect($csv->acceptsCsvImports())->toBeTrue()
        ->and($manual->acceptsCsvImports())->toBeTrue()
        ->and($basiq->acceptsCsvImports())->toBeFalse();
});

test('account has many bank imports', function () {
    $account = Account::factory()->create();
    BankImport::factory()->count(2)->for($account)->for($account->user)->create();

    expect($account->bankImports)->toHaveCount(2);
});
