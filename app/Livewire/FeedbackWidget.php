<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use App\Enums\FeedbackCategory;
use Exception;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

final class FeedbackWidget extends Component
{
    public bool $showModal = false;

    public string $category = 'bug';

    public string $description = '';

    public string $screenshot = '';

    public string $pageUrl = '';

    public string $userAgent = '';

    public string $viewport = '';

    public function captureScreenshot(string $url, string $userAgent, string $viewport): ?string
    {
        $screenshotUrl = config('services.feedback.screenshot_url');

        if (! $screenshotUrl || ! app()->isLocal()) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->get($screenshotUrl.'/screenshot', ['url' => $url]);

            if ($response->failed()) {
                report("Feedback screenshot failed: {$response->body()}");

                return null;
            }

            return $response->json('screenshot');
        } catch (Exception $e) {
            report("Feedback screenshot failed: {$e->getMessage()}");

            return null;
        }
    }

    public function setScreenshotAndOpen(string $screenshot, string $pageUrl, string $userAgent, string $viewport): void
    {
        $this->screenshot = $screenshot;
        $this->pageUrl = $pageUrl;
        $this->userAgent = $userAgent;
        $this->viewport = $viewport;
        $this->showModal = true;
    }

    public function submit(GitHubServiceContract $github): void
    {
        $this->validate([
            'category' => ['required', Rule::enum(FeedbackCategory::class)],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $screenshotPath = $this->saveScreenshot();
        $screenshotUrl = null;

        $screenshotFailed = false;

        if ($screenshotPath) {
            try {
                $screenshotUrl = $github->uploadScreenshot($screenshotPath);
            } catch (RequestException|ConnectionException $e) {
                report($e);
                $screenshotFailed = true;
            }
        }

        $submission = FeedbackSubmission::from([
            'category' => FeedbackCategory::from($this->category),
            'description' => $this->description,
            'screenshotPath' => $screenshotUrl,
            'pageUrl' => $this->pageUrl,
            'userAgent' => $this->userAgent,
            'viewport' => $this->viewport,
            'userName' => auth()->user()->name,
            'userEmail' => auth()->user()->email,
        ]);

        try {
            $issueUrl = $github->createFeedbackIssue($submission);
        } catch (RequestException|ConnectionException $e) {
            report($e);

            Flux::toast(
                text: 'Could not create the issue — please try again',
                heading: 'GitHub error',
                variant: 'danger',
            );

            return;
        }

        $issueNumber = basename($issueUrl);

        $this->resetForm();

        if ($screenshotFailed) {
            Flux::toast(
                text: "Screenshot could not be attached — {$issueUrl}",
                heading: "Issue #{$issueNumber} created",
                variant: 'warning',
            );
        } else {
            Flux::toast(
                text: $issueUrl,
                heading: "Issue #{$issueNumber} created",
                variant: 'success',
            );
        }

        $this->dispatch('feedback-issue-created', url: $issueUrl);
    }

    public function render(): View
    {
        return view('livewire.feedback-widget');
    }

    private function resetForm(): void
    {
        $this->showModal = false;
        $this->category = 'bug';
        $this->description = '';
        $this->screenshot = '';
        $this->pageUrl = '';
        $this->userAgent = '';
        $this->viewport = '';
    }

    private function saveScreenshot(): ?string
    {
        if ($this->screenshot === '') {
            return null;
        }

        $pngDataUrlPrefix = 'data:image/png;base64,';

        if (! str_starts_with($this->screenshot, $pngDataUrlPrefix)) {
            return null;
        }

        $data = mb_substr($this->screenshot, mb_strlen($pngDataUrlPrefix));

        if ($data === '') {
            return null;
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            return null;
        }

        $filename = 'feedback/'.Str::uuid().'.png';
        Storage::disk('public')->put($filename, $decoded);

        return $filename;
    }
}
