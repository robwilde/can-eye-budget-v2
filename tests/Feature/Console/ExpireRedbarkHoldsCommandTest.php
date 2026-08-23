<?php

declare(strict_types=1);

use App\Enums\ImportSource;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\RedbarkAccount;
use App\Models\Transaction;

test('orphaned redbark holds past the ttl are soft-deleted; live and adopted rows are left alone', function () {
    $account = Account::factory()->create(['import_source' => ImportSource::Redbark]);

    RedbarkAccount::factory()->create([
        'account_id' => $account->id,
        'raw_transactions_payload' => [['id' => 'rb_live', 'accountId' => 'rb_live']],
    ]);

    $orphaned = Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => TransactionSource::Redbark,
        'status' => TransactionStatus::Pending,
        'redbark_id' => 'rb_old',
        'post_date' => now()->subDays(20),
    ]);

    $recent = Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => TransactionSource::Redbark,
        'status' => TransactionStatus::Pending,
        'redbark_id' => 'rb_recent',
        'post_date' => now()->subDays(3),
    ]);

    $live = Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => TransactionSource::Redbark,
        'status' => TransactionStatus::Pending,
        'redbark_id' => 'rb_live',
        'post_date' => now()->subDays(20),
    ]);

    $adopted = Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => TransactionSource::Csv,
        'status' => TransactionStatus::Pending,
        'redbark_id' => 'rb_csv',
        'post_date' => now()->subDays(20),
    ]);

    $this->artisan('app:expire-redbark-holds')->assertSuccessful();

    expect(Transaction::find($orphaned->id))->toBeNull()
        ->and($orphaned->fresh()->trashed())->toBeTrue()
        ->and($recent->fresh()->status)->toBe(TransactionStatus::Pending)
        ->and($live->fresh()->status)->toBe(TransactionStatus::Pending)
        ->and(Transaction::find($adopted->id))->not->toBeNull()
        ->and($adopted->fresh()->status)->toBe(TransactionStatus::Posted);
});

test('--dry-run reports without changing any transaction', function () {
    $account = Account::factory()->create(['import_source' => ImportSource::Redbark]);

    RedbarkAccount::factory()->create(['account_id' => $account->id]);

    $orphaned = Transaction::factory()->create([
        'account_id' => $account->id,
        'user_id' => $account->user_id,
        'source' => TransactionSource::Redbark,
        'status' => TransactionStatus::Pending,
        'redbark_id' => 'rb_old',
        'post_date' => now()->subDays(20),
    ]);

    $this->artisan('app:expire-redbark-holds', ['--dry-run' => true])->assertSuccessful();

    expect($orphaned->fresh())->not->toBeNull()
        ->and($orphaned->fresh()->status)->toBe(TransactionStatus::Pending)
        ->and($orphaned->fresh()->trashed())->toBeFalse();
});

test('a ttl not exceeding the pending claim window is rejected', function () {
    $this->artisan('app:expire-redbark-holds', ['--days' => SyncRedbarkFeedJob::PENDING_CLAIM_WINDOW_DAYS])
        ->assertFailed();
});
