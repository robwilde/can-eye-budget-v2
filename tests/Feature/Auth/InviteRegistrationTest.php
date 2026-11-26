<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\RegistrationMode;
use App\Livewire\InviteCodeManager;
use App\Models\AuditEvent;
use App\Models\InviteCode;
use App\Models\User;
use App\Models\UserRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

const INVITE_PLAIN = 'abcDEF1234567890abcDEF1234567890';

beforeEach(function (): void {
    config(['budget.registration_mode' => 'invite_only']);
});

function inviteRegistration(array $overrides = []): array
{
    return [
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'password' => 'a-Strong-Passw0rd!',
        'password_confirmation' => 'a-Strong-Passw0rd!',
        'invite_code' => INVITE_PLAIN,
        ...$overrides,
    ];
}

function adminForInvites(): User
{
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    return $user;
}

it('refuses invite-only registration without a valid code', function (?string $code) {
    InviteCode::factory()->withCode(INVITE_PLAIN)->create();
    $payload = inviteRegistration(['invite_code' => $code ?? '']);

    $this->post(route('register.store'), $payload)->assertSessionHasErrors('invite_code');

    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse()
        ->and(InviteCode::query()->whereNotNull('claimed_at')->exists())->toBeFalse();
})->with([
    'wrong code' => ['not-the-code'],
    'empty code' => [''],
    'missing code' => [null],
]);

it('registers with a valid code and claims it for the new user', function () {
    $invite = InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    $this->post(route('register.store'), inviteRegistration())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'invitee@example.com')->sole();

    expect($invite->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($invite->fresh()->claimed_at)->not->toBeNull();
});

it('keeps an invite code usable when the signup fails validation', function () {
    $invite = InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    $this->post(route('register.store'), inviteRegistration(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    $this->post(route('register.store'), inviteRegistration(['password_confirmation' => 'different']))->assertSessionHasErrors('password');

    expect($invite->fresh()->claimed_at)->toBeNull();

    $this->post(route('register.store'), inviteRegistration())->assertSessionHasNoErrors();

    expect($invite->fresh()->claimed_at)->not->toBeNull();
});

it('refuses a second signup with an already claimed code', function () {
    InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    $this->post(route('register.store'), inviteRegistration())->assertSessionHasNoErrors();
    auth()->logout();
    $this->flushSession();

    $this->post(route('register.store'), inviteRegistration(['email' => 'second@example.com']))->assertSessionHasErrors('invite_code');

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeFalse();
});

it('refuses revoked and expired codes', function (string $state) {
    InviteCode::factory()->withCode(INVITE_PLAIN)->{$state}()->create();

    $this->post(route('register.store'), inviteRegistration())->assertSessionHasErrors('invite_code');

    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
})->with(['revoked', 'expired']);

it('gives the same error for every kind of bad code', function () {
    InviteCode::factory()->withCode(INVITE_PLAIN)->revoked()->create();

    InviteCode::factory()->withCode('expiredexpiredexpiredexpired0000')->expired()->create();
    InviteCode::factory()->withCode('claimedclaimedclaimedclaimed0000')->claimed()->create();

    foreach ([INVITE_PLAIN, 'expiredexpiredexpiredexpired0000', 'claimedclaimedclaimedclaimed0000', 'nope'] as $code) {
        $this->post(route('register.store'), inviteRegistration(['invite_code' => $code]))
            ->assertSessionHasErrors(['invite_code' => 'This invite code is not valid.']);
    }
});

it('rolls back the code claim when month-end rule provisioning fails', function () {
    $invite = InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    Event::listen('eloquent.creating: '.UserRule::class, static function (UserRule $rule): void {
        throw new RuntimeException('Month-end rule provisioning failed');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('register.store'), inviteRegistration()))->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeFalse()
        ->and($invite->fresh()->claimed_at)->toBeNull()
        ->and($invite->fresh()->claimed_by_user_id)->toBeNull();
});

it('lets only one of two signups claim the same code', function () {
    $invite = InviteCode::factory()->withCode(INVITE_PLAIN)->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect(InviteCode::claim(INVITE_PLAIN, $first))->toBeTrue()
        ->and(InviteCode::claim(INVITE_PLAIN, $second))->toBeFalse()
        ->and($invite->fresh()->claimed_by_user_id)->toBe($first->id);
});

it('stores only a hash of the code', function () {
    $admin = adminForInvites();

    $issued = InviteCode::issue($admin);

    $row = DB::table('invite_codes')->where('id', $issued['invite']->id)->first();

    expect($row->code_hash)->toBe(hash('sha256', $issued['code']))
        ->and(json_encode($row))->not->toContain($issued['code'])
        ->and($row->code_hint)->toBe(mb_substr($issued['code'], -4))
        ->and(mb_strlen($issued['code']))->toBe(32)
        ->and($issued['invite']->toArray())->not->toHaveKey('code_hash');
});

it('does not flash the invite code into the session when validation fails', function () {
    InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    $this->post(route('register.store'), inviteRegistration(['email' => 'not-an-email']))->assertSessionHasErrors('email');

    expect(session()->getOldInput('invite_code'))->toBeNull()
        ->and(session()->getOldInput('name'))->toBe('Invitee');
});

it('does not ask for a code when registration is open', function () {
    config(['budget.registration_mode' => 'open']);

    $this->post(route('register.store'), inviteRegistration(['invite_code' => '']))->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'invitee@example.com')->exists())->toBeTrue();
});

it('shows the code field only in invite-only mode', function () {
    $this->get(route('register'))->assertOk()->assertSee('data-test="invite-code-input"', false);

    config(['budget.registration_mode' => 'open']);

    $this->get(route('register'))->assertOk()->assertDontSee('data-test="invite-code-input"', false);
});

it('refuses direct creation when registration is closed', function () {
    config(['budget.registration_mode' => 'closed']);

    expect(fn () => app(App\Actions\Fortify\CreateNewUser::class)->create(inviteRegistration()))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('refuses invite code generation for a non-admin', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.invite-codes'))->assertForbidden();

    Livewire::actingAs($user)->test(InviteCodeManager::class)->assertForbidden();

    expect(InviteCode::query()->count())->toBe(0);
});

it('refuses a non-admin calling generate or revoke directly', function () {
    $admin = adminForInvites();
    $invite = InviteCode::issue($admin)['invite'];
    $user = User::factory()->create();

    $generating = Livewire::actingAs($admin)->test(InviteCodeManager::class);
    $revoking = Livewire::actingAs($admin)->test(InviteCodeManager::class);
    auth()->setUser($user);

    $generating->call('generate')->assertForbidden();
    $revoking->call('revoke', $invite->id)->assertForbidden();

    expect(InviteCode::query()->count())->toBe(1)->and($invite->fresh()->revoked_at)->toBeNull();
});

it('records an audit event when an admin generates an invite code', function () {
    $admin = adminForInvites();

    Livewire::actingAs($admin)->test(InviteCodeManager::class)
        ->set('validDays', 3)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('data-test="new-invite-code"', false);

    $event = AuditEvent::query()->where('action', 'livewire.invite-code-manager.generate')->sole();
    $invite = InviteCode::query()->sole();

    expect($event->user_id)->toBe($admin->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($invite->created_by_user_id)->toBe($admin->id)
        ->and($invite->expires_at->isBetween(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()))->toBeTrue();
});

it('lets an admin revoke an unused code and audits it', function () {
    $admin = adminForInvites();
    $invite = InviteCode::factory()->withCode(INVITE_PLAIN)->create();

    Livewire::actingAs($admin)->test(InviteCodeManager::class)->call('revoke', $invite->id);

    expect($invite->fresh()->revoked_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'livewire.invite-code-manager.revoke')->sole()->user_id)->toBe($admin->id);

    auth()->logout();

    $this->post(route('register.store'), inviteRegistration())->assertSessionHasErrors('invite_code');
});

it('does not revoke a claimed code', function () {
    $admin = adminForInvites();
    $invite = InviteCode::factory()->claimed()->create();

    Livewire::actingAs($admin)->test(InviteCodeManager::class)->call('revoke', $invite->id);

    expect($invite->fresh()->revoked_at)->toBeNull();
});

it('rejects an out-of-range validity', function (int $days) {
    Livewire::actingAs(adminForInvites())->test(InviteCodeManager::class)
        ->set('validDays', $days)
        ->call('generate')
        ->assertHasErrors(['validDays']);

    expect(InviteCode::query()->count())->toBe(0);
})->with([0, -1, 91]);

it('shows an admin the plain code once and only the hint afterwards', function () {
    $admin = adminForInvites();

    $component = Livewire::actingAs($admin)->test(InviteCodeManager::class)->call('generate');
    $code = $component->get('newCode');

    expect($code)->toHaveLength(32);

    $this->actingAs($admin)->get(route('admin.invite-codes'))->assertOk()->assertDontSee($code);
});

it('resolves the registration mode with BUDGET_REGISTRATION_MODE winning over the legacy switch', function (mixed $mode, mixed $legacy, RegistrationMode $expected) {
    expect(RegistrationMode::resolve($mode, $legacy))->toBe($expected);
})->with([
    'nothing set is open' => [null, true, RegistrationMode::Open],
    'legacy false closes' => [null, false, RegistrationMode::Closed],
    'legacy true opens' => [null, true, RegistrationMode::Open],
    'blank mode falls back to legacy' => ['  ', false, RegistrationMode::Closed],
    'mode wins over legacy true' => ['invite_only', true, RegistrationMode::InviteOnly],
    'mode wins over legacy false' => ['open', false, RegistrationMode::Open],
    'mode closed' => ['closed', true, RegistrationMode::Closed],
    'unknown mode closes' => ['nonsense', true, RegistrationMode::Closed],
    'mode is case and space tolerant' => [' Invite_Only ', true, RegistrationMode::InviteOnly],
]);

it('wires the mode into the budget and fortify config at load', function (string $mode, string $expected, bool $routes) {
    $before = [getenv('BUDGET_REGISTRATION_MODE'), $_ENV['BUDGET_REGISTRATION_MODE'] ?? null, $_SERVER['BUDGET_REGISTRATION_MODE'] ?? null];

    putenv('BUDGET_REGISTRATION_MODE='.$mode);
    $_ENV['BUDGET_REGISTRATION_MODE'] = $mode;
    $_SERVER['BUDGET_REGISTRATION_MODE'] = $mode;

    try {
        $budget = require config_path('budget.php');
        $fortify = require config_path('fortify.php');

        expect($budget['registration_mode'])->toBe($expected)
            ->and(in_array(Laravel\Fortify\Features::registration(), $fortify['features'], true))->toBe($routes);
    } finally {
        $before[0] === false ? putenv('BUDGET_REGISTRATION_MODE') : putenv('BUDGET_REGISTRATION_MODE='.$before[0]);
        $_ENV['BUDGET_REGISTRATION_MODE'] = $before[1] ?? '';
        $_SERVER['BUDGET_REGISTRATION_MODE'] = $before[2] ?? '';
    }
})->with([
    'closed removes the register routes' => ['closed', 'closed', false],
    'open keeps them' => ['open', 'open', true],
    'invite only keeps them' => ['invite_only', 'invite_only', true],
    'unknown closes' => ['nonsense', 'closed', false],
]);
