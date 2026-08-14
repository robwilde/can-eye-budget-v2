<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RedbarkServiceContract;
use App\Models\RedbarkFeed;

/**
 * The Redbark API key is per-user, so the client cannot be a container singleton the way
 * BasiqService is. Jobs and components type-hint this factory and build a client for the
 * feed they are working on.
 */
final readonly class RedbarkClientFactory
{
    public function __construct(private string $baseUrl) {}

    public function for(RedbarkFeed $feed): RedbarkServiceContract
    {
        return new RedbarkService($feed->api_key, $this->baseUrl);
    }
}
