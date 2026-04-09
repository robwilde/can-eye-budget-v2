<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use App\Enums\FeedbackCategory;
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

test('submit uploads screenshot and creates issue with image URL', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png');
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(function (FeedbackSubmission $submission) {
            return $submission->description === 'Something is broken'
                && $submission->category->value === 'bug'
                && $submission->screenshotPath === 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png';
        })
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/99');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    $component = Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080')
        ->set('description', 'Something is broken')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('showModal'))->toBeFalse()
        ->and($component->get('description'))->toBe('')
        ->and($component->get('screenshot'))->toBe('')
        ->and($component->get('category'))->toBe('bug');
});

test('submit saves screenshot to local storage before uploading', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->withArgs(fn (string $path) => str_starts_with($path, 'feedback/'))
        ->andReturn('https://github.com/test/releases/download/feedback-assets/uploaded.png');
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
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

test('submit uploads screenshot even when release ID is not configured', function () {
    Storage::fake('public');
    config(['services.github.feedback_release_id' => '']);

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->withArgs(fn (string $path) => str_starts_with($path, 'feedback/'))
        ->andReturn('https://github.com/test/releases/download/feedback-assets/uploaded.png');
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->screenshotPath === 'https://github.com/test/releases/download/feedback-assets/uploaded.png')
        ->andReturn('https://github.com/test/issues/1');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test', 'Mozilla', '800x600')
        ->set('description', 'Screenshot without config')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Storage::disk('public')->allFiles('feedback'))->toHaveCount(1);
});

test('submit works with all feedback categories', function (string $category) {
    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->category === FeedbackCategory::from($category))
        ->andReturn('https://github.com/test/issues/1');

    app()->instance(GitHubServiceContract::class, $mock);

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('description', "Testing {$category}")
        ->set('category', $category)
        ->set('pageUrl', 'https://app.test')
        ->set('userAgent', 'Test')
        ->set('viewport', '800x600')
        ->call('submit')
        ->assertHasNoErrors();
})->with(['bug', 'feature-request', 'question']);

test('submit handles malformed base64 screenshot gracefully', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->screenshotPath === null)
        ->andReturn('https://github.com/test/issues/1');

    app()->instance(GitHubServiceContract::class, $mock);

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', 'not-base64-at-all!!!', 'https://app.test', 'Mozilla', '800x600')
        ->set('description', 'Bad screenshot data')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();
});

test('submit requires authentication', function () {
    Livewire::test(FeedbackWidget::class)
        ->set('description', 'Unauthenticated attempt')
        ->set('category', 'bug')
        ->set('pageUrl', 'https://app.test')
        ->set('userAgent', 'Test')
        ->set('viewport', '800x600')
        ->call('submit');
})->throws(ErrorException::class);

test('submit creates issue without screenshot when upload throws exception', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->andThrow(new Illuminate\Http\Client\RequestException(
            new Illuminate\Http\Client\Response(
                new GuzzleHttp\Psr7\Response(404, [], '{"message": "Not Found"}')
            )
        ));
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->screenshotPath === null)
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/42');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    $component = Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080')
        ->set('description', 'Upload will fail but issue should still be created')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('showModal'))->toBeFalse()
        ->and($component->get('description'))->toBe('')
        ->and($component->get('screenshot'))->toBe('');
});

test('submit creates issue without screenshot when upload throws ConnectionException', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->andThrow(new Illuminate\Http\Client\ConnectionException('Connection refused'));
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->withArgs(fn (FeedbackSubmission $s) => $s->screenshotPath === null)
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/50');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    $component = Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080')
        ->set('description', 'Upload will fail with connection error')
        ->set('category', 'bug')
        ->call('submit')
        ->assertHasNoErrors();

    expect($component->get('showModal'))->toBeFalse()
        ->and($component->get('description'))->toBe('')
        ->and($component->get('screenshot'))->toBe('');
});

test('submit shows success toast with issue number after submission', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/77');

    app()->instance(GitHubServiceContract::class, $mock);

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->set('description', 'Feature works great')
        ->set('category', 'feature-request')
        ->set('pageUrl', 'https://app.test')
        ->set('userAgent', 'Test')
        ->set('viewport', '800x600')
        ->call('submit')
        ->assertDispatched('toast-show', slots: ['text' => 'https://github.com/robwilde/can-eye-budget-v2/issues/77', 'heading' => 'Issue #77 created'], dataset: ['variant' => 'success'])
        ->assertDispatched('feedback-issue-created', url: 'https://github.com/robwilde/can-eye-budget-v2/issues/77');
});

test('submit shows warning toast when screenshot upload fails', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $mock = Mockery::mock(GitHubServiceContract::class);
    $mock->shouldReceive('uploadScreenshot')
        ->once()
        ->andThrow(new Illuminate\Http\Client\RequestException(
            new Illuminate\Http\Client\Response(
                new GuzzleHttp\Psr7\Response(500, [], '{"message": "Internal Server Error"}')
            )
        ));
    $mock->shouldReceive('createFeedbackIssue')
        ->once()
        ->andReturn('https://github.com/robwilde/can-eye-budget-v2/issues/88');

    app()->instance(GitHubServiceContract::class, $mock);

    $pngBase64 = base64_encode('fake-png-data');

    Livewire::actingAs($user)
        ->test(FeedbackWidget::class)
        ->call('setScreenshotAndOpen', "data:image/png;base64,{$pngBase64}", 'https://app.test/dashboard', 'Mozilla/5.0', '1920x1080')
        ->set('description', 'Screenshot upload will fail')
        ->set('category', 'bug')
        ->call('submit')
        ->assertDispatched('toast-show', slots: ['text' => 'Screenshot could not be attached — https://github.com/robwilde/can-eye-budget-v2/issues/88', 'heading' => 'Issue #88 created'], dataset: ['variant' => 'warning'])
        ->assertDispatched('feedback-issue-created', url: 'https://github.com/robwilde/can-eye-budget-v2/issues/88');
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
