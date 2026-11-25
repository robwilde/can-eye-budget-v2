<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('shows the reset action on the profile page', function () {
    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Reset my data')
        ->assertSee('data-test="reset-data-button"', false);
});

it('refuses to reset data with a wrong password', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)->test('pages::settings.reset-data')
        ->set('password', 'not-the-password')
        ->call('resetData')
        ->assertHasErrors(['password']);

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to reset data without a password', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    Livewire::actingAs($user)->test('pages::settings.reset-data')
        ->call('resetData')
        ->assertHasErrors(['password']);

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('resets the signed-in users data with the right password and goes to the dashboard', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->for($user)->for($account)->debit()->create();
    $otherAccount = Account::factory()->for($other)->create();

    Livewire::actingAs($user)->test('pages::settings.reset-data')
        ->set('password', 'password')
        ->call('resetData')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Transaction::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Account::query()->whereKey($otherAccount->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('records an audit event when data is reset', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.reset-data')
        ->set('password', 'password')
        ->call('resetData');

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.reset-data.resetData')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('shows an error and deletes nothing when the reset fails', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();
    DB::unprepared("CREATE TRIGGER fail_account_delete BEFORE DELETE ON accounts BEGIN SELECT RAISE(ABORT, 'delete failed'); END");

    Livewire::actingAs($user)->test('pages::settings.reset-data')
        ->set('password', 'password')
        ->call('resetData')
        ->assertHasErrors(['password'])
        ->assertNoRedirect();

    expect(Account::query()->where('user_id', $user->id)->count())->toBe(1);
});
