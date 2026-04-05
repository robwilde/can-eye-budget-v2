<?php

/** @noinspection PhpUnhandledExceptionInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\DTOs\FeedbackSubmission;
use App\Enums\FeedbackCategory;
use App\Services\GitHubService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function makeFeedbackSubmission(array $overrides = []): FeedbackSubmission
{
    return FeedbackSubmission::from(array_merge([
        'category' => FeedbackCategory::Bug,
        'description' => 'The balance is wrong on the dashboard',
        'screenshotPath' => 'feedback/abc123.png',
        'pageUrl' => 'https://can-eye-budget.test/dashboard',
        'userAgent' => 'Mozilla/5.0 Test Browser',
        'viewport' => '1920x1080',
        'userName' => 'Test User',
        'userEmail' => 'test@example.com',
    ], $overrides));
}

test('creates a GitHub issue with correct POST request', function () {
    Http::fake([
        'api.github.com/repos/robwilde/can-eye-budget-v2/issues' => Http::response([
            'html_url' => 'https://github.com/robwilde/can-eye-budget-v2/issues/99',
        ], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission();

    $issueUrl = $service->createFeedbackIssue($submission);

    expect($issueUrl)->toBe('https://github.com/robwilde/can-eye-budget-v2/issues/99');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.github.com/repos/robwilde/can-eye-budget-v2/issues'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/vnd.github+json');
    });
});

test('issue title contains emoji, category label, and truncated description', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission([
        'category' => FeedbackCategory::FeatureRequest,
        'description' => 'Add dark mode toggle to the settings page',
    ]);

    $service->createFeedbackIssue($submission);

    Http::assertSent(function (Request $request) {
        $title = $request->data()['title'];

        return str_contains($title, '[Feature Request]')
            && str_contains($title, 'Add dark mode toggle');
    });
});

test('issue body contains description and metadata', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission();

    $service->createFeedbackIssue($submission);

    Http::assertSent(function (Request $request) {
        $body = $request->data()['body'];

        return str_contains($body, 'The balance is wrong on the dashboard')
            && str_contains($body, 'https://can-eye-budget.test/dashboard')
            && str_contains($body, 'Test User')
            && str_contains($body, '1920x1080')
            && str_contains($body, 'feedback/abc123.png');
    });
});

test('issue labels match the feedback category', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');

    $service->createFeedbackIssue(makeFeedbackSubmission(['category' => FeedbackCategory::Bug]));
    $service->createFeedbackIssue(makeFeedbackSubmission(['category' => FeedbackCategory::Question]));

    $sentRequests = Http::recorded();

    expect($sentRequests[0][0]->data()['labels'])->toBe(['bug'])
        ->and($sentRequests[1][0]->data()['labels'])->toBe(['question']);
});

test('issue body excludes screenshot section when screenshot path is null', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission(['screenshotPath' => null]);

    $service->createFeedbackIssue($submission);

    Http::assertSent(function (Request $request) {
        $body = $request->data()['body'];

        return ! str_contains($body, 'Screenshot');
    });
});

test('throws RequestException on GitHub API error', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $service = new GitHubService(token: 'bad-token', repo: 'robwilde/can-eye-budget-v2');

    $service->createFeedbackIssue(makeFeedbackSubmission());
})->throws(RequestException::class);

test('service is resolvable from the container', function () {
    config(['services.github.token' => 'container-test-token']);
    config(['services.github.feedback_repo' => 'robwilde/can-eye-budget-v2']);

    $service = app(GitHubService::class);

    expect($service)->toBeInstanceOf(GitHubService::class);
});
