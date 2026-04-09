<?php

/** @noinspection JSUnresolvedReference */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Models\User;

$testScreenshotBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

$findWidget = <<<'JS'
    document.querySelector('[data-testid="feedback-widget"]').getAttribute('wire:id')
JS;

test('floating feedback button is visible on authenticated page', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertPresent('[data-testid="feedback-trigger"]')
        ->assertPresent('[data-testid="feedback-widget"]');
});

test('modal opens with Send Feedback heading when triggered via script', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Send Feedback');
});

test('category buttons switch active category', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Send Feedback')
        ->click("\u{1F4A1} Feature Request")
        ->assertScript(<<<JS
            Livewire.find({$findWidget}).get('category') === 'feature-request'
        JS);
});

test('description textarea accepts input', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Send Feedback')
        ->type('[data-flux-textarea]', 'This is a test description')
        ->assertValue('[data-flux-textarea]', 'This is a test description');
});

test('screenshot preview renders when screenshot data is present', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Screenshot preview')
        ->assertPresent('img[alt="Screenshot"]');
});

test('screenshot preview is absent when no screenshot data', function () use ($findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget}).set('showModal', true)
    JS);

    $page->assertSee('Send Feedback')
        ->assertDontSee('Screenshot preview');
});

test('cancel button closes the modal', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Send Feedback')
        ->click('Cancel')
        ->assertDontSee('Send Feedback');
});

test('validation error shows when description is empty', function () use ($testScreenshotBase64, $findWidget) {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->script(<<<JS
        Livewire.find({$findWidget})
            .call('setScreenshotAndOpen', '{$testScreenshotBase64}', window.location.href, navigator.userAgent, '1920x1080')
    JS);

    $page->assertSee('Send Feedback')
        ->click('Submit Feedback')
        ->assertSee('description');
});

test('no JavaScript errors on page load', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/dashboard');

    $page->assertPresent('[data-testid="feedback-widget"]')
        ->assertScript('window.__jsErrors === undefined || window.__jsErrors.length === 0');
});
