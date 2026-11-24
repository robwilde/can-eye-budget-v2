<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\PreviewNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Fixtures\Livewire\PreviewGatedComponent;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'preview:budget.goals_enabled'])->get('/_preview-gated', fn (): string => 'gated page');
    Route::middleware(['web', 'auth', 'preview'])->get('/_preview-only', fn (): string => 'preview only page');
});

function previewUser(bool $on): User
{
    $user = User::factory()->create();

    if ($on) {
        $user->updatePreferences(['previewFeatures' => true]);
    }

    return $user->fresh();
}

it('redirects a user without preview features to the dashboard with a preview notice', function () {
    config(['budget.goals_enabled' => true]);

    $this->actingAs(previewUser(false))
        ->get('/_preview-gated')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(PreviewNotice::SESSION_KEY);

    $this->actingAs(previewUser(false))
        ->followingRedirects()
        ->get('/_preview-gated')
        ->assertOk()
        ->assertSee('This feature is in preview')
        ->assertSee('data-test="preview-notice"', false);
});

it('opens a preview page for a user who opted in', function () {
    config(['budget.goals_enabled' => true]);

    $this->actingAs(previewUser(true))->get('/_preview-gated')->assertOk()->assertSee('gated page');
});

it('returns 404 for a preview page when its deployment flag is off', function (bool $optedIn) {
    config(['budget.goals_enabled' => false]);

    $this->actingAs(previewUser($optedIn))->get('/_preview-gated')->assertNotFound();
})->with([
    'opted in' => [true],
    'opted out' => [false],
]);

it('treats a missing preview preference as off', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['preferences' => json_encode(['theme' => 'dark'])]);

    expect($user->fresh()->hasPreviewFeatures())->toBeFalse();

    DB::table('users')->where('id', $user->id)->update(['preferences' => null]);

    expect($user->fresh()->hasPreviewFeatures())->toBeFalse();
});

it('counts only an explicit true as opted in', function () {
    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update(['preferences' => json_encode(['previewFeatures' => 'yes'])]);

    expect($user->fresh()->hasPreviewFeatures())->toBeFalse();
});

it('selects only opted-in users for background jobs', function () {
    $in = previewUser(true);
    $out = previewUser(false);
    $explicitlyOff = User::factory()->create();
    $explicitlyOff->updatePreferences(['previewFeatures' => false]);
    $none = User::factory()->create();
    DB::table('users')->where('id', $none->id)->update(['preferences' => null]);

    expect(User::query()->withPreviewFeatures()->pluck('id')->all())->toBe([$in->id])
        ->and(User::query()->withPreviewFeatures()->whereKey($out->id)->exists())->toBeFalse();
});

it('records an audit event when preview features are toggled', function () {
    $user = previewUser(false);
    $other = previewUser(false);
    $otherBefore = $other->fresh()->getAttributes();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->assertSet('previewFeatures', false)
        ->set('previewFeatures', true)
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.preferences.save')->sole();

    expect($user->fresh()->hasPreviewFeatures())->toBeTrue()
        ->and($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($other->fresh()->getAttributes())->toBe($otherBefore);

    Livewire::actingAs($user->fresh())->test('pages::settings.preferences')
        ->assertSet('previewFeatures', true)
        ->set('previewFeatures', false)
        ->call('save');

    expect($user->fresh()->hasPreviewFeatures())->toBeFalse();
});

it('applies the preview check with no deployment flag to a user who has not opted in', function () {
    $this->actingAs(previewUser(false))->get('/_preview-only')->assertRedirect(route('dashboard'));
    $this->actingAs(previewUser(true))->get('/_preview-only')->assertOk()->assertSee('preview only page');
});

it('sends a guest to login rather than the dashboard', function () {
    $this->get('/_preview-only')->assertRedirect(route('login'));
});

it('gates a livewire component page for a user who has not opted in', function () {
    config(['budget.goals_enabled' => true]);
    Route::middleware(['web', 'auth'])->get('/_preview-component', PreviewGatedComponent::class);

    $this->actingAs(previewUser(false))
        ->get('/_preview-component')
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(PreviewNotice::SESSION_KEY);

    $this->actingAs(previewUser(true))->get('/_preview-component')->assertOk()->assertSee('gated component');
});

it('returns 404 from a gated livewire component page when its deployment flag is off', function () {
    config(['budget.goals_enabled' => false]);
    Route::middleware(['web', 'auth'])->get('/_preview-component', PreviewGatedComponent::class);

    $this->actingAs(previewUser(true))->get('/_preview-component')->assertNotFound();
});

it('runs a gated livewire action for a user who opted in', function () {
    config(['budget.goals_enabled' => true]);

    Livewire::actingAs(previewUser(true))->test(PreviewGatedComponent::class)
        ->call('run')
        ->assertSet('runs', 1);
});

it('stops a livewire action once the user turns preview features off', function () {
    config(['budget.goals_enabled' => true]);
    $user = previewUser(true);

    $component = Livewire::actingAs($user)->test(PreviewGatedComponent::class);
    $user->updatePreferences(['previewFeatures' => false]);

    $component->call('run')->assertForbidden();
});

it('stops a livewire action once the deployment flag is turned off', function () {
    config(['budget.goals_enabled' => true]);

    $component = Livewire::actingAs(previewUser(true))->test(PreviewGatedComponent::class);
    config(['budget.goals_enabled' => false]);

    $component->call('run')->assertNotFound();
});
