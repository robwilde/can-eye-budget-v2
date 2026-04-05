<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use App\Livewire\FeedbackWidget;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('component renders for authenticated user', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->assertSuccessful();
});

test('setScreenshotAndOpen sets data and opens modal', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', 'data:image/png;base64,abc123', 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080');

    expect($component->get('showModal'))->toBeTrue()
        ->and($component->get('screenshot'))->toBe('data:image/png;base64,abc123')
        ->and($component->get('pageUrl'))->toBe('https://app.test/dashboard')
        ->and($component->get('userAgent'))->toBe('Mozilla/5.0')
        ->and($component->get('viewport'))->toBe('1920x1080');
});

test('submit validates required fields', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('category', '')
        ->set('description', '')
        ->call('submit')
        ->assertHasErrors(['category', 'description']);
});

test('submit validates description max length', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('category', 'bug')
        ->set('description', str_repeat('a', 2001))
        ->call('submit')
        ->assertHasErrors(['description']);
});

test('submit calls GitHubService and resets form', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(function (FeedbackSubmission $submission) {
            return $submission->description === 'Something is broken'
                && $submission->category->value === 'bug'
                && $submission->pageUrl === 'https://app.test/dashboard'
                && $submission->userName !== '';
        })
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/99');

    app()->instance(GitHubServiceContract::class, $mock);

    $component = Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', 'data:image/png;base64,iVBORw0KGgo=', 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080')
        ->set('description', 'Something is broken')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('showModal'))->toBeFalse()
        ->and($component->get('description'))->toBe('')
        ->and($component->get('screenshot'))->toBe('')
        ->and($component->get('category'))->toBe('bug');
});

test('submit saves screenshot to storage when provided', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(function (FeedbackSubmission $submission) {
            return $submission->screenshotPath !== null
                && str_starts_with($submission->screenshotPath, 'feedback/');
        })
        ->andReturn('https://github.com/test/issues/1');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test', 'Mozilla', '800x600')
        ->set('description', 'Test with screenshot')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Storage::disk('public')->allFiles('feedback'))->toHaveCount(1);
});

test('submit works without screenshot', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->screenshotPath === null)
        ->andReturn('https://github.com/test/issues/1');

    app()->instance(GitHubServiceContract::class, $mock);

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('description', 'No screenshot needed')
        ->set('category', 'question')
        ->set('pageUrl', 'https://app.test')
        ->set('userAgent', 'Test')
        ->set('viewport', '800x600')
        ->call('submit')
        ->assertHasNoErrors();
});

test('submit handles GitHub API failure gracefully', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->andThrow(new Illuminate\Http\Client\RequestException(
            new Illuminate\Http\Client\Response(
                new GuzzleHttp\Psr7\Response(401, [], '{"message": "Bad credentials"}')
            )
        ));

    app()->instance(GitHubServiceContract::class, $mock);

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('description', 'This will fail')
        ->set('category', 'bug')
        ->set('pageUrl', 'https://app.test')
        ->call('submit');
})->throws(Illuminate\Http\Client\RequestException::class);
