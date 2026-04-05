<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class GitHubService implements GitHubServiceContract
{
    public function __construct(
        private string $token,
        private string $repo,
    ) {}

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function createFeedbackIssue(FeedbackSubmission $submission): string
    {
        $title = sprintf(
            '%s [%s] %s',
            $submission->category->emoji(),
            $submission->category->label(),
            Str::limit($submission->description, 80),
        );

        $body = $this->buildIssueBody($submission);

        $response = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->throw()
            ->post("https://api.github.com/repos/{$this->repo}/issues", [
                'title' => $title,
                'body' => $body,
                'labels' => [$submission->category->githubLabel()],
            ]);

        return $response->json('html_url');
    }

    private function buildIssueBody(FeedbackSubmission $submission): string
    {
        $screenshotSection = '';
        if ($submission->screenshotPath) {
            $screenshotSection = <<<MD

            ## Screenshot

            Screenshot saved at: `{$submission->screenshotPath}`
            MD;
        }

        return <<<MD
        ## Description

        {$submission->description}
        {$screenshotSection}

        ## Metadata

        | Field | Value |
        |-------|-------|
        | Page URL | {$submission->pageUrl} |
        | User | {$submission->userName} ({$submission->userEmail}) |
        | Viewport | {$submission->viewport} |
        | User Agent | {$submission->userAgent} |
        | Submitted | {$this->now()} |
        MD;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }
}
