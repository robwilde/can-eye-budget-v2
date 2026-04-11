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
use Illuminate\Support\Facades\Storage;

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

test('issue body contains description and metadata with screenshot as markdown image', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission([
        'screenshotPath' => 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/abc.png',
    ]);

    $service->createFeedbackIssue($submission);

    Http::assertSent(function (Request $request) {
        $body = $request->data()['body'];

        return str_contains($body, 'The balance is wrong on the dashboard')
            && str_contains($body, 'https://can-eye-budget.test/dashboard')
            && str_contains($body, 'Test User')
            && str_contains($body, '1920x1080')
            && str_contains($body, '![Screenshot]');
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

test('uploads screenshot to GitHub release and returns download URL', function () {
    Storage::fake('public');
    Storage::disk('public')->put('feedback/test.png', 'fake-image-data');

    Http::fake([
        'uploads.github.com/*' => Http::response([
            'browser_download_url' => 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png',
        ], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2', releaseId: '12345');

    $url = $service->uploadScreenshot('feedback/test.png');

    expect($url)->toBe('https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'uploads.github.com/repos/robwilde/can-eye-budget-v2/releases/12345/assets')
            && $request->hasHeader('Content-Type', 'image/png')
            && $request->hasHeader('Authorization', 'Bearer test-token');
    });
});

test('retries without labels when GitHub returns 422 on label creation', function () {
    Http::fake([
        'api.github.com/repos/robwilde/can-eye-budget-v2/issues' => Http::sequence()
            ->push(['message' => 'Validation Failed'], 422)
            ->push(['html_url' => 'https://github.com/robwilde/can-eye-budget-v2/issues/42'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission();

    $issueUrl = $service->createFeedbackIssue($submission);

    expect($issueUrl)->toBe('https://github.com/robwilde/can-eye-budget-v2/issues/42');

    $sentRequests = Http::recorded();

    expect($sentRequests)->toHaveCount(2)
        ->and($sentRequests[0][0]->data())->toHaveKey('labels')
        ->and($sentRequests[1][0]->data())->not->toHaveKey('labels');
});

test('retries without labels when GitHub returns 403 on label creation', function () {
    Http::fake([
        'api.github.com/repos/robwilde/can-eye-budget-v2/issues' => Http::sequence()
            ->push(['message' => 'Resource not accessible by integration'], 403)
            ->push(['html_url' => 'https://github.com/robwilde/can-eye-budget-v2/issues/43'], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');

    $issueUrl = $service->createFeedbackIssue(makeFeedbackSubmission());

    expect($issueUrl)->toBe('https://github.com/robwilde/can-eye-budget-v2/issues/43');

    $sentRequests = Http::recorded();

    expect($sentRequests)->toHaveCount(2)
        ->and($sentRequests[0][0]->data())->toHaveKey('labels')
        ->and($sentRequests[1][0]->data())->not->toHaveKey('labels');
});

test('issue title truncates descriptions longer than 80 characters', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['html_url' => 'https://github.com/test/issues/1'], 201),
    ]);

    $longDescription = str_repeat('This is a very long description. ', 10);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');
    $submission = makeFeedbackSubmission(['description' => $longDescription]);

    $service->createFeedbackIssue($submission);

    Http::assertSent(function (Request $request) {
        $title = $request->data()['title'];
        $titleParts = explode('] ', $title, 2);
        $descriptionPart = $titleParts[1] ?? '';

        return str_ends_with($descriptionPart, '...')
            && mb_strlen($descriptionPart) <= 83;
    });
});

test('upload auto-discovers existing release when release ID is not configured', function () {
    Storage::fake('public');
    Storage::disk('public')->put('feedback/test.png', 'fake-image-data');

    Http::fake([
        'api.github.com/repos/robwilde/can-eye-budget-v2/releases/tags/feedback-assets' => Http::response([
            'id' => 67890,
        ], 200),
        'uploads.github.com/*' => Http::response([
            'browser_download_url' => 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png',
        ], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');

    $url = $service->uploadScreenshot('feedback/test.png');

    expect($url)->toBe('https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png');

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), 'uploads.github.com/repos/robwilde/can-eye-budget-v2/releases/67890/assets');
    });
});

test('upload creates release when none exists and release ID is not configured', function () {
    Storage::fake('public');
    Storage::disk('public')->put('feedback/test.png', 'fake-image-data');

    Http::fake([
        'api.github.com/repos/robwilde/can-eye-budget-v2/releases/tags/feedback-assets' => Http::response([
            'message' => 'Not Found',
        ], 404),
        'api.github.com/repos/robwilde/can-eye-budget-v2/releases' => Http::response([
            'id' => 99999,
        ], 201),
        'uploads.github.com/*' => Http::response([
            'browser_download_url' => 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png',
        ], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2');

    $url = $service->uploadScreenshot('feedback/test.png');

    expect($url)->toBe('https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png');

    $sentRequests = Http::recorded();

    expect($sentRequests)->toHaveCount(3);

    expect($sentRequests[0][0]->url())
        ->toContain('api.github.com/repos/robwilde/can-eye-budget-v2/releases/tags/feedback-assets');

    expect($sentRequests[1][0]->url())
        ->toBe('https://api.github.com/repos/robwilde/can-eye-budget-v2/releases');
    expect($sentRequests[1][0]->data())->toHaveKey('tag_name', 'feedback-assets');

    expect($sentRequests[2][0]->url())
        ->toContain('uploads.github.com/repos/robwilde/can-eye-budget-v2/releases/99999/assets');
});

test('upload skips release lookup when release ID is configured', function () {
    Storage::fake('public');
    Storage::disk('public')->put('feedback/test.png', 'fake-image-data');

    Http::fake([
        'uploads.github.com/*' => Http::response([
            'browser_download_url' => 'https://github.com/robwilde/can-eye-budget-v2/releases/download/feedback-assets/test.png',
        ], 201),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2', releaseId: '12345');

    $service->uploadScreenshot('feedback/test.png');

    $sentRequests = Http::recorded();

    expect($sentRequests)->toHaveCount(1);
    expect($sentRequests[0][0]->url())
        ->toContain('uploads.github.com/repos/robwilde/can-eye-budget-v2/releases/12345/assets');
});

test('upload throws ConnectionException when GitHub is unreachable', function () {
    Storage::fake('public');
    Storage::disk('public')->put('feedback/test.png', 'fake-image-data');

    Http::fake([
        'uploads.github.com/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('Connection refused'),
    ]);

    $service = new GitHubService(token: 'test-token', repo: 'robwilde/can-eye-budget-v2', releaseId: '12345');

    $service->uploadScreenshot('feedback/test.png');
})->throws(Illuminate\Http\Client\ConnectionException::class);

test('service is resolvable from the container', function () {
    config(['services.github.token' => 'container-test-token']);
    config(['services.github.feedback_repo' => 'robwilde/can-eye-budget-v2']);

    $service = app(GitHubService::class);

    expect($service)->toBeInstanceOf(GitHubService::class);
});
