<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class BasiqService
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://au-api.basiq.io',
    ) {}

    public function serverToken(): string
    {
        return Cache::remember('basiq:server_token', 1200, fn (): string => $this->resolveToken('SERVER_ACCESS'));
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function clientToken(string $basiqUserId): string
    {
        return $this->resolveToken('CLIENT_ACCESS', ['userId' => $basiqUserId]);
    }

    public function api(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->serverToken())
            ->withHeaders(['basiq-version' => '3.0'])
            ->throw();
    }

    /**
     * @param  array<string, string>  $body
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws RuntimeException
     */
    private function resolveToken(string $scope, array $body = []): string
    {
        $token = Http::asForm()
            ->withHeaders([
                'Authorization' => "Basic $this->apiKey",
                'basiq-version' => '3.0',
            ])
            ->post("$this->baseUrl/token", $body + ['scope' => $scope])
            ->throw()
            ->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException("Basiq token response missing access_token for scope: $scope");
        }

        return $token;
    }
}
