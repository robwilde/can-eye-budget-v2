<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Enums\PayFrequency;
use App\Enums\SupportedLocale;
use App\Enums\Theme;
use App\Enums\TransactionPeriod;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

test('preferences page is displayed to a signed in user only', function () {
    $this->get(route('preferences.edit'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());

    $this->get(route('preferences.edit'))->assertOk()->assertSee('Suggest rules when categorizing');
});

test('user can turn rule suggestions off and back on', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->assertSet('rulePromptEnabled', true)
        ->set('rulePromptEnabled', false)
        ->call('save')
        ->assertDispatched('preferences-updated');

    expect($user->fresh()->preferences->rulePromptEnabled)->toBeFalse();

    Livewire::actingAs($user->fresh())->test('pages::settings.preferences')
        ->set('rulePromptEnabled', true)
        ->call('save');

    expect($user->fresh()->preferences->rulePromptEnabled)->toBeTrue();
});

test('saving preferences leaves other users untouched', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.preferences')->set('rulePromptEnabled', false)->call('save');

    expect($other->fresh()->preferences->rulePromptEnabled)->toBeTrue();
});

it('shows every preference field with the saved values', function () {
    $user = User::factory()->create(['base_currency' => 'NZD', 'timezone' => 'Pacific/Auckland', 'month_start_day' => 15]);
    $user->updatePreferences(['theme' => 'dark', 'locale' => 'de', 'defaultPeriod' => 'budget-month']);

    $this->actingAs($user)->get(route('preferences.edit'))
        ->assertOk()
        ->assertSee(['Theme', 'Language', 'Base currency', 'Timezone', 'Month start day', 'Default period']);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->assertSet('theme', 'dark')
        ->assertSet('locale', 'de')
        ->assertSet('baseCurrency', 'NZD')
        ->assertSet('timezone', 'Pacific/Auckland')
        ->assertSet('monthStartDay', 15)
        ->assertSet('defaultPeriod', 'budget-month');
});

it('saves every preference for the signed-in user', function () {
    $user = User::factory()->create(['base_currency' => 'AUD']);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('theme', 'dark')
        ->set('locale', 'de')
        ->set('baseCurrency', 'USD')
        ->set('timezone', 'Australia/Perth')
        ->set('monthStartDay', 20)
        ->set('defaultPeriod', 'budget-month')
        ->set('rulePromptEnabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('preferences-updated');

    $fresh = $user->fresh();

    expect($fresh->base_currency)->toBe('USD')
        ->and($fresh->timezone)->toBe('Australia/Perth')
        ->and($fresh->month_start_day)->toBe(20)
        ->and($fresh->preferences->theme)->toBe(Theme::Dark)
        ->and($fresh->preferences->locale)->toBe(SupportedLocale::German)
        ->and($fresh->preferences->defaultPeriod)->toBe(TransactionPeriod::BudgetMonth)
        ->and($fresh->preferences->rulePromptEnabled)->toBeFalse();
});

it('clears the month start day when none is chosen', function () {
    $user = User::factory()->create(['month_start_day' => 15]);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('monthStartDay', null)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->month_start_day)->toBeNull();
});

it('rejects an unknown timezone', function () {
    $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('timezone', 'Mars/Base')
        ->set('theme', 'dark')
        ->call('save')
        ->assertHasErrors(['timezone'])
        ->assertNotDispatched('preferences-updated');

    $fresh = $user->fresh();

    expect($fresh->timezone)->toBe('Australia/Brisbane')
        ->and($fresh->preferences->theme)->toBe(Theme::System);
});

it('rejects a month start day outside 1 to 28 as a validation error', function (mixed $day) {
    $user = User::factory()->create(['month_start_day' => 10]);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('monthStartDay', $day)
        ->call('save')
        ->assertHasErrors(['monthStartDay']);

    expect($user->fresh()->month_start_day)->toBe(10);
})->with([0, 29, 31, -1]);

it('rejects an unsupported theme, language, currency or default period', function (string $field, string $value) {
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('timezone', 'Europe/Berlin')
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field]);

    expect($user->fresh()->getAttributes())->toBe($before);
})->with([
    'theme' => ['theme', 'neon'],
    'language' => ['locale', 'xx'],
    'currency' => ['baseCurrency', 'XYZ'],
    'default period' => ['defaultPeriod', 'fortnight'],
    'custom range as default period' => ['defaultPeriod', 'custom'],
]);

it('offers budget month as a default period', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->assertSee('Budget Month')
        ->set('defaultPeriod', 'budget-month')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->preferences->defaultPeriod)->toBe(TransactionPeriod::BudgetMonth);
});

it('offers pay cycle as a default period only when a pay cycle is configured', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('defaultPeriod', 'pay-cycle')
        ->call('save')
        ->assertHasErrors(['defaultPeriod']);

    $user->update(['pay_amount' => 500_000, 'pay_frequency' => PayFrequency::Fortnightly, 'next_pay_date' => now()->addDays(3)]);

    Livewire::actingAs($user->fresh())->test('pages::settings.preferences')
        ->set('defaultPeriod', 'pay-cycle')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->preferences->defaultPeriod)->toBe(TransactionPeriod::PayCycle);
});

it('applies a theme change in the browser without a reload', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('theme', 'dark')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('theme-changed', theme: 'dark');
});

it('leaves stored transaction amounts unchanged when the base currency changes', function () {
    $user = User::factory()->create(['base_currency' => 'AUD']);
    $account = Account::factory()->for($user)->create(['currency' => 'AUD']);
    $debit = Transaction::factory()->for($user)->for($account)->debit()->create(['amount' => 12_345, 'currency' => 'AUD']);
    $credit = Transaction::factory()->for($user)->for($account)->credit()->create(['amount' => 67_890, 'currency' => 'AUD']);
    $before = Transaction::query()->orderBy('id')->get(['id', 'amount', 'currency', 'direction'])->toArray();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('baseCurrency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->base_currency)->toBe('USD')
        ->and(Transaction::query()->orderBy('id')->get(['id', 'amount', 'currency', 'direction'])->toArray())->toBe($before)
        ->and($debit->fresh()->amount)->toBe(12_345)
        ->and($credit->fresh()->amount)->toBe(67_890);
});

it('records an audit event and saves preferences only for the signed-in user', function () {
    $user = User::factory()->create(['base_currency' => 'AUD', 'timezone' => 'Australia/Brisbane']);
    $other = User::factory()->create(['base_currency' => 'AUD', 'timezone' => 'Australia/Brisbane']);
    $otherBefore = $other->fresh()->getAttributes();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('theme', 'dark')
        ->set('baseCurrency', 'EUR')
        ->set('timezone', 'Europe/Berlin')
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', 'livewire.pages::settings.preferences.save')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($user->fresh()->base_currency)->toBe('EUR')
        ->and($other->fresh()->getAttributes())->toBe($otherBefore);
});

it('renders the dark theme when the user prefers dark', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['theme' => 'dark']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-appearance="dark"', false)
        ->assertDontSee('data-appearance="light"', false);
});

it('renders the light theme when the user prefers light', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['theme' => 'light']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-appearance="light"', false);
});

it('keeps the allowed currencies containing the new base currency', function () {
    $user = User::factory()->create(['base_currency' => 'AUD']);
    $user->updatePreferences(['allowedCurrencies' => ['AUD', 'NZD']]);

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->set('baseCurrency', 'USD')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->preferences->allowedCurrencies)->toContain('USD')
        ->and($user->fresh()->allowedCurrencies())->toContain('USD');
});

it('opens the transactions list and reports on the saved default period', function () {
    $user = User::factory()->create(['month_start_day' => 15]);
    $user->updatePreferences(['defaultPeriod' => 'budget-month']);

    Livewire::actingAs($user)->test(App\Livewire\TransactionList::class)->assertSet('period', 'budget-month');
    Livewire::actingAs($user)->test(App\Livewire\CategoryReport::class)->assertSet('period', 'budget-month');
});

it('lets a period in the URL override the saved default period', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['defaultPeriod' => 'budget-month']);

    Livewire::withQueryParams(['period' => '3m'])->actingAs($user)->test(App\Livewire\TransactionList::class)->assertSet('period', '3m');
    Livewire::withQueryParams(['period' => '3m'])->actingAs($user)->test(App\Livewire\CategoryReport::class)->assertSet('period', '3m');
});

it('falls back to this month when the saved default is pay cycle without a pay cycle', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['defaultPeriod' => 'pay-cycle']);

    Livewire::actingAs($user)->test(App\Livewire\TransactionList::class)->assertSet('period', 'this-month');
    Livewire::actingAs($user)->test(App\Livewire\CategoryReport::class)->assertSet('period', 'this-month');
});

it('offers budget month in the transactions list and report period selects', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(App\Livewire\TransactionList::class)->assertSeeHtml('value="budget-month"');
    Livewire::actingAs($user)->test(App\Livewire\CategoryReport::class)->assertSeeHtml('value="budget-month"');
});

it('requires a language and shows the app language for a user who has not chosen one', function () {
    $user = User::factory()->create();
    expect($user->preferences->locale)->toBeNull();

    Livewire::actingAs($user)->test('pages::settings.preferences')
        ->assertSet('locale', 'en')
        ->set('locale', '')
        ->call('save')
        ->assertHasErrors(['locale']);

    expect($user->fresh()->preferences->locale)->toBeNull();
});

it('keeps showing a live pause when other preferences are saved', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['rulePromptHiddenUntil' => now()->addDay()->toIso8601String()]);

    Livewire::actingAs($user->fresh())->test('pages::settings.preferences')
        ->assertSee('Suggestions are paused until')
        ->set('theme', 'dark')
        ->call('save')
        ->assertSee('Suggestions are paused until');

    expect($user->fresh()->preferences->rulePromptHiddenUntil)->not->toBeNull();
});

it('keeps the printed report on the light theme whatever the user prefers', function () {
    $user = User::factory()->create();
    $user->updatePreferences(['theme' => 'dark']);

    $this->actingAs($user)->get(route('reports.print'))
        ->assertOk()
        ->assertSee('data-appearance="light"', false)
        ->assertDontSee('data-appearance="dark"', false);
});
