<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\BankImportStatus;
use App\Models\Account;
use App\Models\BankImport;
use App\Models\User;

test('factory creates a valid bank import', function () {
    $import = BankImport::factory()->create();

    expect($import)->toBeInstanceOf(BankImport::class)
        ->and($import->exists)->toBeTrue();
});

test('default factory creates a pending import', function () {
    $import = BankImport::factory()->create();

    expect($import->status)->toBe(BankImportStatus::Pending);
});

test('completed state sets completed status with counts', function () {
    $import = BankImport::factory()->completed()->create();

    expect($import->status)->toBe(BankImportStatus::Completed)
        ->and($import->imported_count)->toBe(100)
        ->and($import->completed_at)->not->toBeNull();
});

test('failed state sets failed status with error summary', function () {
    $import = BankImport::factory()->failed()->create();

    expect($import->status)->toBe(BankImportStatus::Failed)
        ->and($import->error_summary)->not->toBeNull();
});

test('belongs to a user', function () {
    $user = User::factory()->create();
    $import = BankImport::factory()->for($user)->create();

    expect($import->user->id)->toBe($user->id);
});

test('belongs to an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $import = BankImport::factory()->for($user)->for($account)->create();

    expect($import->account->id)->toBe($account->id);
});

test('cascades on user delete', function () {
    $user = User::factory()->create();
    BankImport::factory()->for($user)->create();

    $user->delete();

    expect(BankImport::query()->count())->toBe(0);
});

test('cascades on account delete', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    BankImport::factory()->for($user)->for($account)->create();

    $account->delete();

    expect(BankImport::query()->count())->toBe(0);
});

test('status is cast to enum', function () {
    $import = BankImport::factory()->create();

    expect($import->status)->toBeInstanceOf(BankImportStatus::class);
});

test('column_mapping is cast to array', function () {
    $mapping = ['date' => 'Posted Date', 'amount' => 'Amount'];
    $import = BankImport::factory()->create(['column_mapping' => $mapping]);

    expect($import->column_mapping)->toBe($mapping);
});

test('isComplete is true for terminal statuses', function () {
    $completed = BankImport::factory()->completed()->create();
    $failed = BankImport::factory()->failed()->create();
    $pending = BankImport::factory()->create();

    expect($completed->isComplete())->toBeTrue()
        ->and($failed->isComplete())->toBeTrue()
        ->and($pending->isComplete())->toBeFalse();
});
