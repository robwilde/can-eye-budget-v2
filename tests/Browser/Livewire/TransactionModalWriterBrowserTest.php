<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

function writerBrowserUser(): array
{
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);

    return [$user, $account];
}

test('the transaction modal creates an entered transaction', function () {
    [$user] = writerBrowserUser();

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script("Livewire.dispatch('open-transaction-modal', { date: '2026-04-19' })");

    $page->assertPresent('[data-testid="transaction-submit"]')
        ->fill('textarea[wire\\:model\\.blur="descriptionInput"]', '12.50 Browser coffee')
        ->click('[data-testid="transaction-submit"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $row = Transaction::query()->where('user_id', $user->id)->where('description', 'Browser coffee')->sole();

    expect($row->amount)->toBe(1250)
        ->and($row->direction)->toBe(TransactionDirection::Debit)
        ->and($row->post_date->toDateString())->toBe('2026-04-19');
});

test('the transaction modal edits an entered transaction as a new version', function () {
    [$user, $account] = writerBrowserUser();
    $row = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1250,
        'direction' => TransactionDirection::Debit,
        'description' => 'Browser coffee',
        'post_date' => '2026-04-19',
        'transfer_pair_id' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script("Livewire.dispatch('edit-transaction', { id: {$row->id} })");

    $page->assertPresent('[data-testid="transaction-submit"]')
        ->fill('textarea[wire\\:model\\.blur="descriptionInput"]', '20.00 Browser coffee')
        ->click('[data-testid="transaction-submit"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    $current = Transaction::findCurrentVersion($row->id, $user->id);

    expect($current->id)->not->toBe($row->id)
        ->and($current->amount)->toBe(2000)
        ->and($current->parent_transaction_id)->toBe($row->id);
});

test('the transaction modal deletes an entered transaction', function () {
    [$user, $account] = writerBrowserUser();
    $row = Transaction::factory()->for($user)->for($account)->manual()->create([
        'amount' => 1250,
        'direction' => TransactionDirection::Debit,
        'description' => 'Browser coffee',
        'post_date' => '2026-04-19',
        'transfer_pair_id' => null,
    ]);

    $this->actingAs($user);

    $page = visit('/calendar');
    $page->script("Livewire.dispatch('edit-transaction', { id: {$row->id} })");
    $page->script('window.confirm = () => true');

    $page->assertPresent('button[wire\\:click="deleteTransaction"]')
        ->click('button[wire\\:click="deleteTransaction"]')
        ->wait(1)
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->whereKey($row->id)->exists())->toBeFalse();
});
