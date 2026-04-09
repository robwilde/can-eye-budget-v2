<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BasiqServiceContract;
use App\DTOs\BasiqAccount;
use App\DTOs\BasiqJob;
use App\DTOs\BasiqTransaction;
use App\DTOs\BasiqUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use RuntimeException;

final readonly class BasiqService implements BasiqServiceContract
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
     * @throws ConnectionException
     */
    public function createUser(string $email, ?string $mobile = null): BasiqUser
    {
        $payload = ['email' => $email];

        if ($mobile !== null) {
            $payload['mobile'] = $mobile;
        }

        $response = $this->api()->post('/users', $payload)->json();

        return BasiqUser::from($response);
    }

    /**
     * @return Collection<int, BasiqAccount>
     *
     * @throws ConnectionException
     */
    public function getAccounts(string $basiqUserId): Collection
    {
        $response = $this->api()->get("/users/$basiqUserId/accounts")->json();

        return BasiqAccount::collect($response['data'] ?? [], Collection::class);
    }

    /**
     * @param  array<int, string>|null  $filter
     * @return LazyCollection<int, BasiqTransaction>
     */
    public function paginateTransactions(string $basiqUserId, ?array $filter = null): LazyCollection
    {
        return LazyCollection::make(function () use ($basiqUserId, $filter) {
            $url = "/users/$basiqUserId/transactions";
            $query = $filter !== null ? ['filter' => implode(',', $filter)] : null;
            $fetched = 0;
            $total = null;

            do {
                $response = $this->api()->get($url, $query)->json();
                $query = null;
                $data = $response['data'] ?? [];

                $total ??= $response['size'] ?? PHP_INT_MAX;

                foreach ($data as $item) {
                    yield BasiqTransaction::from($item);
                    $fetched++;
                }

                if ($data === [] || $fetched >= $total) {
                    break;
                }

                $url = $response['links']['next'] ?? null;
            } while ($url !== null);
        });
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function createConnection(string $basiqUserId, string $institutionId, string $loginId, string $password): string
    {
        $response = $this->api()->post("/users/{$basiqUserId}/connections", [
            'institution' => ['id' => $institutionId],
            'loginId' => $loginId,
            'password' => $password,
        ])->json();

        $jobUrl = $response['links']['job'] ?? null;

        if (! is_string($jobUrl) || $jobUrl === '') {
            throw new RuntimeException("Basiq connection response missing job link for user: {$basiqUserId}");
        }

        return basename($jobUrl);
    }

    /**
     * @throws ConnectionException
     */
    public function getJob(string $jobId): BasiqJob
    {
        $response = $this->api()->get("/jobs/{$jobId}")->json();

        return BasiqJob::from($response);
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
