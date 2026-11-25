<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PayFrequency;
use App\Enums\Theme;
use App\Livewire\ConnectBank;
use App\Livewire\OnboardingPreferencesStep;
use App\Models\AuditEvent;
use App\Models\RedbarkFeed;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
});

function syncedUnonboardedUser(): User
{
    $user = User::factory()->unonboarded()->withPayCycle()->create();
    $user->forceFill(['primary_account_id' => App\Models\Account::factory()->for($user)->create()->id])->save();
    RedbarkFeed::factory()->for($user)->synced()->create();

    return $user->fresh();
}

it('redirects a user who has not finished onboarding to the setup wizard', function () {
    $this->actingAs(User::factory()->unonboarded()->create());

    foreach (['dashboard', 'transactions', 'accounts', 'reports'] as $route) {
        $this->get(route($route))->assertRedirect(route('connect-bank'));
    }
});

it('lets an unonboarded user open settings', function () {
    $this->actingAs(User::factory()->unonboarded()->create());

    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('preferences.edit'))->assertOk();
    $this->get(route('connect-bank'))->assertOk();
    expect($this->get(route('redbark.setup'))->headers->get('Location'))->not->toBe(route('connect-bank'));
});

it('lets an onboarded user open the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

it('shows the preference step to a user whose feed has already synced', function () {
    $user = syncedUnonboardedUser();

    Livewire::actingAs($user)->test(ConnectBank::class)
        ->assertSet('step', ConnectBank::STEP_PREFERENCES)
        ->assertNoRedirect()
        ->assertSeeLivewire(OnboardingPreferencesStep::class);
});

it('redirects an onboarded user with a synced feed straight to the dashboard', function () {
    $user = syncedUnonboardedUser();
    $user->forceFill(['onboarded_at' => now()])->save();

    Livewire::actingAs($user->fresh())->test(ConnectBank::class)->assertRedirect(route('dashboard'));
});

it('marks onboarding finished when the user skips every step', function () {
    $user = User::factory()->unonboarded()->create();
    $other = User::factory()->unonboarded()->create();

    Livewire::actingAs($user)->test(ConnectBank::class)
        ->call('skipOnboarding')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->onboarded_at)->not->toBeNull()
        ->and($other->fresh()->onboarded_at)->toBeNull();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk();
});

it('marks onboarding finished when the wizard finishes', function () {
    $user = User::factory()->unonboarded()->create();

    Livewire::actingAs($user)->test(ConnectBank::class)->call('finish')->assertRedirect(route('dashboard'));

    expect($user->fresh()->onboarded_at)->not->toBeNull();
});

it('keeps the first finish time when finishing again', function () {
    $user = User::factory()->create(['onboarded_at' => now()->subDays(5)]);
    $before = $user->onboarded_at;

    Livewire::actingAs($user)->test(ConnectBank::class)->call('finish');

    expect($user->fresh()->onboarded_at->equalTo($before))->toBeTrue();
});

it('moves from the pay cycle to the preference step and on to the tags', function () {
    Livewire::actingAs(User::factory()->unonboarded()->create())->test(ConnectBank::class)
        ->dispatch('pay-cycle-confirmed')
        ->assertSet('step', ConnectBank::STEP_PREFERENCES)
        ->dispatch('onboarding-preferences-done')
        ->assertSet('step', ConnectBank::STEP_TAGS);
});

it('saves the onboarding preferences to the preference store with an audit event', function () {
    $user = User::factory()->unonboarded()->create();
    $other = User::factory()->unonboarded()->create();
    $otherBefore = $other->fresh()->getAttributes();

    Livewire::actingAs($user)->test(OnboardingPreferencesStep::class)
        ->set('theme', 'dark')
        ->set('timezone', 'Australia/Perth')
        ->set('baseCurrency', 'NZD')
        ->set('monthStartDay', 12)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('onboarding-preferences-done');

    $fresh = $user->fresh();
    $event = AuditEvent::query()->where('action', 'livewire.onboarding-preferences-step.save')->sole();

    expect($fresh->preferences->theme)->toBe(Theme::Dark)
        ->and($fresh->timezone)->toBe('Australia/Perth')
        ->and($fresh->base_currency)->toBe('NZD')
        ->and($fresh->month_start_day)->toBe(12)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($other->fresh()->getAttributes())->toBe($otherBefore);
});

it('rejects an unknown timezone in the onboarding step and saves nothing', function () {
    $user = User::factory()->unonboarded()->create();

    Livewire::actingAs($user)->test(OnboardingPreferencesStep::class)
        ->set('theme', 'dark')
        ->set('timezone', 'Mars/Base')
        ->call('save')
        ->assertHasErrors(['timezone'])
        ->assertNotDispatched('onboarding-preferences-done');

    expect($user->fresh()->preferences->theme)->toBe(Theme::System);
});

it('skips the preference step without saving anything', function () {
    $user = User::factory()->unonboarded()->create();

    Livewire::actingAs($user)->test(OnboardingPreferencesStep::class)
        ->set('theme', 'dark')
        ->call('skip')
        ->assertDispatched('onboarding-preferences-done');

    expect($user->fresh()->preferences->theme)->toBe(Theme::System);
});

it('backfills onboarded_at for users who finished bank setup', function () {
    $migration = require database_path('migrations/2026_12_08_000000_add_onboarded_at_to_users_table.php');

    $finished = User::factory()->create(['pay_amount' => 100_000, 'pay_frequency' => PayFrequency::Fortnightly, 'next_pay_date' => now()->addDays(3)]);
    RedbarkFeed::factory()->for($finished)->synced()->create();
    $noFeed = User::factory()->create(['pay_amount' => 100_000, 'pay_frequency' => PayFrequency::Fortnightly, 'next_pay_date' => now()->addDays(3)]);
    $noPayCycle = User::factory()->create();
    RedbarkFeed::factory()->for($noPayCycle)->synced()->create();
    $neverSynced = User::factory()->create(['pay_amount' => 100_000, 'pay_frequency' => PayFrequency::Fortnightly, 'next_pay_date' => now()->addDays(3)]);
    RedbarkFeed::factory()->for($neverSynced)->create(['last_synced_at' => null]);

    $migration->down();
    $migration->up();

    $stamped = fn (User $user): mixed => DB::table('users')->where('id', $user->id)->value('onboarded_at');

    expect($stamped($finished))->not->toBeNull()
        ->and($stamped($noFeed))->toBeNull()
        ->and($stamped($noPayCycle))->toBeNull()
        ->and($stamped($neverSynced))->toBeNull();
});

it('shows the finish message when the header skip is used on the Gmail step', function () {
    $user = User::factory()->unonboarded()->create();

    Livewire::actingAs($user)->test(ConnectBank::class)
        ->dispatch('payee-review-finished')
        ->assertSet('step', ConnectBank::STEP_GMAIL)
        ->call('skipOnboarding')
        ->assertRedirect(route('dashboard'));

    expect(session('status'))->toBe('Primary account and pay cycle saved.')
        ->and($user->fresh()->onboarded_at)->not->toBeNull();
});
