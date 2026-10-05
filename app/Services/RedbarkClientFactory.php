<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RedbarkServiceContract;
use App\Models\RedbarkFeed;
use SensitiveParameter;

/**
 * The Redbark API key is per-user, so the client cannot be a container singleton.
 * Jobs and components type-hint this factory and build a client for the
 * feed they are working on.
 */
final readonly class RedbarkClientFactory
{
    public const int VALIDATION_TIMEOUT_SECONDS = 10;

    public function __construct(private string $baseUrl) {}

    public function for(RedbarkFeed $feed): RedbarkServiceContract
    {
        return new RedbarkService($feed->api_key, $this->baseUrl);
    }

    public function forValidation(#[SensitiveParameter] string $apiKey): RedbarkServiceContract
    {
        return new RedbarkService($apiKey, $this->baseUrl, timeoutSeconds: self::VALIDATION_TIMEOUT_SECONDS, maxAttempts: 1);
    }
}
