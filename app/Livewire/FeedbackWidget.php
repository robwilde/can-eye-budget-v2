<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\GitHubServiceContract;
use App\DTOs\FeedbackSubmission;
use App\Enums\FeedbackCategory;
use Flux\Flux;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function setScreenshotAndOpen(string $screenshot, string $pageUrl, string $userAgent, string $viewport): void
    {
        $this->screenshot = $screenshot;
        $this->pageUrl = $pageUrl;
        $this->userAgent = $userAgent;
        $this->viewport = $viewport;
        $this->showModal = true;
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function submit(GitHubServiceContract $github): void
    {
        $this->validate([
            'category' => ['required', 'string'],
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

        $issueUrl = $github->createFeedbackIssue($submission);
        $issueNumber = basename($issueUrl);

        $this->resetForm();

        if ($screenshotFailed) {
            Flux::toast(
                text: "Issue #{$issueNumber} created but screenshot could not be attached",
                heading: 'Feedback submitted',
                variant: 'warning',
            );
        } else {
            Flux::toast(
                text: "Issue #{$issueNumber} created on GitHub",
                heading: 'Feedback submitted',
                variant: 'success',
            );
        }
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

        $data = preg_replace('/^data:image\/\w+;base64,/', '', $this->screenshot);

        if ($data === null || $data === '') {
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
