<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\FeedbackSubmission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

interface GitHubServiceContract
{
    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function createFeedbackIssue(FeedbackSubmission $submission): string;

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function uploadScreenshot(string $storagePath): string;
}
