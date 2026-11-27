<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use App\Livewire\FeedbackWidget;
use App\Livewire\TransactionModal;
use App\Models\ApiKey;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['budget.api' => true, 'budget.mcp' => true, 'budget.bills_page' => true]);
});

dataset('app routes', [
    'dashboard' => 'dashboard',
    'transactions' => 'transactions',
    'accounts' => 'accounts',
    'rules' => 'rules',
    'settings api keys' => 'api-keys.edit',
    'bills' => 'bills',
    'reports' => 'reports',
]);

function registrationPayload(array $overrides = []): array
{
    return [
        'name' => 'New Person',
        'email' => 'new.person@example.com',
        'password' => 'a-Strong-Passw0rd!',
        'password_confirmation' => 'a-Strong-Passw0rd!',
        ...$overrides,
    ];
}

function verificationLinkFor(User $user): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
}

it('redirects an unverified user to the verification notice from every app route', function (string $route) {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route($route))->assertRedirect(route('verification.notice'));
})->with('app routes');

it('redirects an unverified user to the verification notice even before onboarding', function () {
    $user = User::factory()->unverified()->unonboarded()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->get(route('connect-bank'))->assertRedirect(route('verification.notice'));
    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
});

it('lets a verified user through the app routes', function (string $route) {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route($route))->assertOk();
})->with('app routes');

it('keeps the profile page reachable for an unverified user so the address can be corrected', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
});

it('sends a verification email on registration', function () {
    Notification::fake();

    $this->post(route('register.store'), registrationPayload())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('verifies through the link inside the registration email', function () {
    Notification::fake();

    $this->post(route('register.store'), registrationPayload())->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.person@example.com')->sole();
    $link = null;

    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$link): bool {
        $link = $notification->toMail($user)->actionUrl;

        return true;
    });

    $this->actingAs($user)->get($link)->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('sends a newly registered user to the verification notice, not an onboarding loop', function () {
    $this->post(route('register.store'), registrationPayload())->assertRedirect(route('connect-bank', absolute: false));

    $this->get(route('connect-bank'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertOk()->assertSee('Resend verification email');
});

it('verifies the email from the signed link and then reaches onboarding', function () {
    $user = User::factory()->unverified()->unonboarded()->create();

    $this->actingAs($user)->get(verificationLinkFor($user))->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('connect-bank'));
    $this->actingAs($user->fresh())->get(route('connect-bank'))->assertOk();
});

it('verifies the email from the signed link and then reaches the dashboard when onboarded', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationLinkFor($user));

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

it('refuses a verification link signed for another user', function () {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationLinkFor($other))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification email to an unverified user', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect()->assertSessionHas('status', 'verification-link-sent');

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('throttles verification resends', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    }

    $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);

    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
});

it('keeps the REST api and mcp working for a key created by an unverified user', function () {
    $user = User::factory()->unverified()->create();
    $token = ApiKey::issue($user, 'created before verification', ApiKeyScope::Read, null)['token'];

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/accounts')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'method' => 'tools/list', 'params' => (object) [], 'id' => 1], ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->assertJsonStructure(['result' => ['tools']]);
});

it('does not let an unverified user reach the api key settings page that issues keys', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(route('api-keys.edit'))->assertRedirect(route('verification.notice'));
});

it('makes the first user on an empty instance an admin and still requires verification', function () {
    expect(User::query()->exists())->toBeFalse();

    $this->post(route('register.store'), registrationPayload())->assertSessionHasNoErrors();

    $user = User::query()->sole();

    expect($user->is_admin)->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($user)->get(route('admin.users'))->assertRedirect(route('verification.notice'));
});

it('registers with an invite code and then requires verification', function () {
    config(['budget.registration_mode' => 'invite_only']);
    $plain = 'abcDEF1234567890abcDEF1234567890';
    $invite = InviteCode::factory()->withCode($plain)->create();
    Notification::fake();

    $this->post(route('register.store'), registrationPayload(['invite_code' => $plain]))->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'new.person@example.com')->sole();

    expect($invite->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});

function livewireUpdateFromTagsPage(object $test, User $user, ?callable $beforeUpdate = null): Illuminate\Testing\TestResponse
{
    $html = $test->actingAs($user)->get(route('tags'))->assertOk()->getContent();

    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);

    if ($beforeUpdate !== null) {
        $beforeUpdate();
    }

    return $test->actingAs($user->fresh())->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => (object) [], 'calls' => []]],
    ], ['X-Livewire' => 'true', 'Accept' => 'text/html']);
}

it('accepts a Livewire action from a verified user', function () {
    livewireUpdateFromTagsPage($this, User::factory()->create())->assertOk();
});

it('refuses a Livewire action from a page rendered before the user became unverified', function () {
    $user = User::factory()->create();

    livewireUpdateFromTagsPage($this, $user, fn () => $user->forceFill(['email_verified_at' => null])->save())
        ->assertRedirect(route('verification.notice'));
});

it('hides the transaction modal and feedback widget from an unverified user on the profile page', function () {
    $this->actingAs(User::factory()->unverified()->create())->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSeeLivewire(TransactionModal::class)
        ->assertDontSeeLivewire(FeedbackWidget::class)
        ->assertDontSee('topbar-log-spend', false)
        ->assertDontSee('mobile-tabbar-fab', false);

    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertOk()
        ->assertSeeLivewire(TransactionModal::class)
        ->assertSeeLivewire(FeedbackWidget::class)
        ->assertSee('topbar-log-spend', false)
        ->assertSee('mobile-tabbar-fab', false);
});

it('links the verification notice to the profile page so the address can be corrected', function () {
    $this->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'))
        ->assertOk()
        ->assertSee('data-test="verify-email-change-address"', false)
        ->assertSee(route('profile.edit'), false);
});
