<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

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
