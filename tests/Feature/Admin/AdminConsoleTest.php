<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\LlmOperation;
use App\Livewire\AdminAuditLog;
use App\Livewire\AdminLlmUsage;
use App\Livewire\AdminUsers;
use App\Models\AuditEvent;
use App\Models\LlmUsage;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo('2026-07-15 10:00:00');
    $this->admin = User::factory()->create(['is_admin' => true, 'name' => 'Ada Admin', 'email' => 'ada@example.test']);
});

function consoleUsage(User $user, string $model, int $cost, string $date, LlmOperation $operation = LlmOperation::Chat): LlmUsage
{
    $usage = LlmUsage::query()->create(['user_id' => $user->id, 'operation' => $operation, 'provider' => 'openai', 'model' => $model, 'prompt_tokens' => 100, 'completion_tokens' => 50, 'cost' => $cost]);
    $usage->forceFill(['created_at' => $date.' 12:00:00', 'updated_at' => $date.' 12:00:00'])->save();

    return $usage;
}

it('refuses the admin console to a non-admin', function (string $route) {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route($route))->assertForbidden();
    $this->actingAs($this->admin)->get(route($route))->assertOk();
})->with(['admin.users', 'admin.audit', 'admin.llm-usage']);

it('sends guests to login and refuses every console component to a non-admin', function () {
    auth()->logout();
    $this->get(route('admin.users'))->assertRedirect(route('login'));

    $user = User::factory()->create();

    foreach ([AdminUsers::class, AdminAuditLog::class, AdminLlmUsage::class] as $component) {
        Livewire::actingAs($user)->test($component)->assertForbidden();
    }
});

it('refuses every admin user action called by a non-admin', function (string $method, bool $withId) {
    Notification::fake();
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    $component = Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('startRemoval', $victim->id)->set('confirmEmail', 'victim@example.test');
    $this->actingAs(User::factory()->create());

    $component->call($method, ...($withId ? [$victim->id] : []))->assertForbidden();

    expect($victim->fresh())->not->toBeNull()->and($victim->fresh()->is_admin)->toBeFalse();
    Notification::assertNothingSent();
})->with([['toggleAdmin', true], ['sendResetLink', true], ['startRemoval', true], ['removeUser', false], ['cancelRemoval', false]]);

it('refuses a removal when the acting admin was demoted meanwhile', function () {
    $other = User::factory()->create(['is_admin' => true]);
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    $component = Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('startRemoval', $victim->id)->set('confirmEmail', 'victim@example.test');

    User::query()->whereKey($this->admin->id)->update(['is_admin' => false]);

    $component->call('removeUser')->assertForbidden();

    expect(User::query()->whereKey($victim->id)->exists())->toBeTrue()->and($other->fresh()->is_admin)->toBeTrue();
});

it('lists and searches users', function () {
    User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@example.test']);
    User::factory()->create(['name' => 'Carol Coder', 'email' => 'carol@example.test']);

    Livewire::actingAs($this->admin)->test(AdminUsers::class)
        ->assertSee('Bob Builder')->assertSee('Carol Coder')
        ->set('search', 'carol')
        ->assertSee('Carol Coder')->assertDontSee('Bob Builder')
        ->set('search', '100%')
        ->assertDontSee('Carol Coder');
});

it('toggles the admin flag for another user', function () {
    $other = User::factory()->create();

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('toggleAdmin', $other->id)->assertHasNoErrors();

    expect($other->fresh()->is_admin)->toBeTrue();

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('toggleAdmin', $other->id);

    expect($other->fresh()->is_admin)->toBeFalse();
});

it('refuses to demote the last instance admin', function () {
    $second = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('toggleAdmin', $second->id)->assertHasNoErrors();

    expect($second->fresh()->is_admin)->toBeFalse();

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('toggleAdmin', $this->admin->id)->assertHasErrors(['admin']);

    expect($this->admin->fresh()->is_admin)->toBeTrue()->and(User::query()->where('is_admin', true)->count())->toBe(1);

    $other = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($other)->test(AdminUsers::class)->call('toggleAdmin', $other->id)->assertHasNoErrors();

    expect($other->fresh()->is_admin)->toBeFalse();
});

it('answers not found for a user that does not exist', function () {
    expect(fn () => Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('toggleAdmin', 9999))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('sends a password reset link', function () {
    Notification::fake();
    $other = User::factory()->create();

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('sendResetLink', $other->id)->assertHasNoErrors();

    Notification::assertSentTo($other, Illuminate\Auth\Notifications\ResetPassword::class);
});

it('refuses to let an admin remove their own user', function () {
    $component = Livewire::actingAs($this->admin)->test(AdminUsers::class)
        ->call('startRemoval', $this->admin->id)
        ->assertHasErrors(['admin'])
        ->assertSet('removingId', null)
        ->set('confirmEmail', $this->admin->email)
        ->call('removeUser');

    expect(User::query()->whereKey($this->admin->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'livewire.admin-users.removeUser')->sole()->user_id)->toBe($this->admin->id);
});

it('requires the user\'s email to be typed before removal', function () {
    $victim = User::factory()->create(['email' => 'victim@example.test']);

    $component = Livewire::actingAs($this->admin)->test(AdminUsers::class)
        ->call('startRemoval', $victim->id)
        ->set('confirmEmail', 'someone-else@example.test')
        ->call('removeUser')
        ->assertHasErrors(['confirmEmail']);

    expect(User::query()->whereKey($victim->id)->exists())->toBeTrue();

    $component->set('confirmEmail', '  VICTIM@example.test ')->call('removeUser')->assertHasNoErrors();

    expect(User::query()->whereKey($victim->id)->exists())->toBeFalse();
});

it('records an audit event when an admin removes a user', function () {
    $victim = User::factory()->create(['email' => 'victim@example.test']);

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('startRemoval', $victim->id)->set('confirmEmail', 'victim@example.test')->call('removeUser');

    $event = AuditEvent::query()->where('action', 'livewire.admin-users.removeUser')->sole();

    expect($event->user_id)->toBe($this->admin->id)->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('removes a user together with their data', function () {
    $victim = User::factory()->create(['email' => 'victim@example.test']);
    $account = App\Models\Account::factory()->for($victim)->create();
    App\Models\Transaction::factory()->for($victim)->for($account)->create();

    Livewire::actingAs($this->admin)->test(AdminUsers::class)->call('startRemoval', $victim->id)->set('confirmEmail', 'victim@example.test')->call('removeUser');

    expect(App\Models\Account::query()->where('user_id', $victim->id)->count())->toBe(0)->and(App\Models\Transaction::query()->where('user_id', $victim->id)->count())->toBe(0);
});

it('filters the audit trail by user', function () {
    $other = User::factory()->create(['email' => 'other@example.test']);
    AuditEvent::query()->create(['user_id' => $this->admin->id, 'action' => 'http.login', 'outcome' => AuditOutcome::Success, 'created_at' => now()]);
    AuditEvent::query()->create(['user_id' => $other->id, 'action' => 'http.logout', 'outcome' => AuditOutcome::Success, 'created_at' => now()]);

    Livewire::actingAs($this->admin)->test(AdminAuditLog::class)
        ->assertSee('http.login')->assertSee('http.logout')
        ->set('user', 'other@example')
        ->assertSee('http.logout')->assertDontSee('http.login');
});

it('filters the audit trail by action, outcome and date and ignores a bad filter', function () {
    AuditEvent::query()->create(['user_id' => $this->admin->id, 'action' => 'http.alpha', 'outcome' => AuditOutcome::Success, 'created_at' => '2026-07-10 10:00:00']);
    AuditEvent::query()->create(['user_id' => $this->admin->id, 'action' => 'http.beta', 'outcome' => AuditOutcome::Failure, 'created_at' => '2026-07-14 10:00:00']);

    Livewire::actingAs($this->admin)->test(AdminAuditLog::class)
        ->set('action', 'alpha')->assertSee('http.alpha')->assertDontSee('http.beta')
        ->set('action', '')->set('outcome', 'failure')->assertSee('http.beta')->assertDontSee('http.alpha')
        ->set('outcome', '')->set('from', '2026-07-12')->assertSee('http.beta')->assertDontSee('http.alpha')
        ->set('from', '')->set('to', '2026-07-11')->assertSee('http.alpha')->assertDontSee('http.beta')
        ->set('to', 'not a date')->assertHasErrors(['to'])->assertSee('http.alpha')->assertSee('http.beta');
});

it('pages the audit trail and shows no request data', function () {
    foreach (range(1, 55) as $i) {
        AuditEvent::query()->create(['user_id' => $this->admin->id, 'action' => 'http.bulk', 'outcome' => AuditOutcome::Success, 'request_id' => 'REQ-'.$i, 'created_at' => now()]);
    }

    $component = Livewire::actingAs($this->admin)->test(AdminAuditLog::class);

    $columns = array_keys($component->instance()->events->first()->getAttributes());

    expect($component->instance()->events->count())->toBe(50)->and($component->instance()->events->total())->toBe(55)
        ->and($columns)->toBe(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'outcome', 'request_id', 'created_at']);
});

it('totals LLM usage cost per model within the selected dates', function () {
    $user = User::factory()->create(['email' => 'spender@example.test']);
    consoleUsage($user, 'gpt-4o-mini', 120, '2026-07-10');
    consoleUsage($user, 'gpt-4o-mini', 30, '2026-07-12');
    consoleUsage($user, 'gpt-4o', 500, '2026-07-12');
    consoleUsage($user, 'gpt-4o-mini', 9999, '2026-06-01');
    consoleUsage($user, 'gpt-4o', 7777, '2026-07-20', LlmOperation::Title);

    $component = Livewire::actingAs($this->admin)->test(AdminLlmUsage::class)
        ->set('from', '2026-07-01')->set('to', '2026-07-15');

    $report = $component->instance()->report;
    $models = array_column($report['models'], 'cost', 'label');

    expect($models)->toBe(['gpt-4o' => 500, 'gpt-4o-mini' => 150])
        ->and($report['total'])->toBe(650)->and($report['calls'])->toBe(3)
        ->and(array_column($report['operations'], 'cost', 'label'))->toBe(['chat' => 650])
        ->and($report['users'][0]['label'])->toBe('spender@example.test')->and($report['users'][0]['cost'])->toBe(650);

    $component->assertSee('$6.50');
});

it('defaults the usage range to the last 30 days and ignores a bad date', function () {
    $user = User::factory()->create();
    consoleUsage($user, 'm', 100, '2026-07-10');
    consoleUsage($user, 'm', 900, '2026-05-01');

    $component = Livewire::actingAs($this->admin)->test(AdminLlmUsage::class)->assertSet('from', '2026-06-16')->assertSet('to', '2026-07-15');

    expect($component->instance()->report['total'])->toBe(100);

    $component->set('from', 'garbage')->assertHasErrors(['from']);

    expect($component->instance()->report['total'])->toBe(1000)->and($component->instance()->report['calls'])->toBe(2);
});
