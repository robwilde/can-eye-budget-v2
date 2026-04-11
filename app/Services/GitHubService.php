<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use UnexpectedValueException;

final readonly class GitHubService implements GitHubServiceContract
{
    public function __construct(
        private string $token,
        private string $repo,
        private string $releaseId = '',
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

        $payload = [
            'title' => $title,
            'body' => $body,
            'labels' => [$submission->category->githubLabel()],
        ];

        $response = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$this->repo}/issues", $payload);

        if ($response->status() === 403 || $response->status() === 422) {
            unset($payload['labels']);
            $response = Http::withToken($this->token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->throw()
                ->post("https://api.github.com/repos/{$this->repo}/issues", $payload);
        } else {
            $response->throw();
        }

        $issueUrl = $response->json('html_url');

        if (! is_string($issueUrl) || $issueUrl === '') {
            throw new UnexpectedValueException('GitHub issue response did not include a valid html_url.');
        }

        return $issueUrl;
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function uploadScreenshot(string $storagePath): string
    {
        $binary = Storage::disk('public')->get($storagePath);
        $filename = Str::uuid().'.png';
        $releaseId = $this->resolveReleaseId();

        $response = Http::withToken($this->token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'Content-Type' => 'image/png',
            ])
            ->withBody($binary, 'image/png')
            ->throw()
            ->post("https://uploads.github.com/repos/{$this->repo}/releases/{$releaseId}/assets?name={$filename}");

        return $response->json('browser_download_url');
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function resolveReleaseId(): string
    {
        if ($this->releaseId !== '') {
            return $this->releaseId;
        }

        $response = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$this->repo}/releases/tags/feedback-assets");

        if ($response->successful()) {
            return (string) $response->json('id');
        }

        $createResponse = Http::withToken($this->token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->throw()
            ->post("https://api.github.com/repos/{$this->repo}/releases", [
                'tag_name' => 'feedback-assets',
                'name' => 'Feedback Assets',
                'body' => 'Auto-created release for storing feedback widget screenshots.',
            ]);

        return (string) $createResponse->json('id');
    }

    private function buildIssueBody(FeedbackSubmission $submission): string
    {
        $screenshotSection = '';
        if ($submission->screenshotPath) {
            $screenshotSection = <<<MD

            ## Screenshot

            ![Screenshot]({$submission->screenshotPath})
            MD;
        }

        $esc = static fn (string $v): string => str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], $v);

        return <<<MD
        ## Description

        {$submission->description}
        {$screenshotSection}

        ## Metadata

        | Field | Value |
        |-------|-------|
        | Page URL | {$esc($submission->pageUrl)} |
        | User | {$esc($submission->userName)} ({$esc($submission->userEmail)}) |
        | Viewport | {$esc($submission->viewport)} |
        | User Agent | {$esc($submission->userAgent)} |
        | Submitted | {$this->now()} |
        MD;
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }
}
