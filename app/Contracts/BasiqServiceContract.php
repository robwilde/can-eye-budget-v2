<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\BasiqAccount;
use App\DTOs\BasiqJob;
use App\DTOs\BasiqTransaction;
use App\DTOs\BasiqUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface BasiqServiceContract
{
    public function serverToken(): string;

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function clientToken(string $basiqUserId): string;

    public function api(): PendingRequest;

    /**
     * @throws ConnectionException
     */
    public function createUser(string $email, ?string $mobile = null): BasiqUser;

    /**
     * @return Collection<int, BasiqAccount>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function getAccounts(string $basiqUserId): Collection;

    /**
     * @param  array<int, string>|null  $filter
     * @return LazyCollection<int, BasiqTransaction>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function paginateTransactions(string $basiqUserId, ?array $filter = null): LazyCollection;

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function createConnection(string $basiqUserId, string $institutionId, string $loginId, string $password): string;

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function getJob(string $jobId): BasiqJob;

    /**
     * @return array<int, string>
     *
     * @throws RequestException
     * @throws ConnectionException
     */
    public function refreshConnections(string $basiqUserId): array;
}
