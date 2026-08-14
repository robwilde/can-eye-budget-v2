<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RedbarkServiceContract;
use App\DTOs\RedbarkAccountData;
use App\DTOs\RedbarkBalanceData;
use App\DTOs\RedbarkConnectionData;
use App\DTOs\RedbarkTransactionData;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkException;
use App\Exceptions\Redbark\RedbarkRateLimitException;
use App\Exceptions\Redbark\RedbarkRequestException;
use App\Exceptions\Redbark\RedbarkServerException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Client for the Redbark CDR API (https://api.redbark.com/v1), a plain Bearer-token
 * REST service. One instance per user key — build it through RedbarkClientFactory.
 *
 * Retries: only transient connection failures are retried here. 429 and 5xx are
 * deliberately NOT retried by the client, because SyncRedbarkFeedJob already has
 * $tries = 5 with backoff and retrying in both places multiplies the delay.
 *
 * Errors: responses are never chained through ->throw(). Status mapping is explicit so
 * every failure carries a machine-readable errorType the job can branch on.
 */
final readonly class RedbarkService implements RedbarkServiceContract
{
    public const int ACCOUNTS_PAGE_SIZE = 200;

    public const int TRANSACTIONS_PAGE_SIZE = 500;

    /** Safety cap so a bad hasMore can never loop forever. */
    public const int MAX_PAGES = 50;

    /** Bounds the recursion when halving a truncated date window. */
    public const int MAX_WINDOW_SPLITS = 6;

    public function __construct(
        #[SensitiveParameter]
        private string $apiKey,
        private string $baseUrl = 'https://api.redbark.com/v1',
    ) {}

    /**
     * @return list<RedbarkAccountData>
     *
     * @throws RedbarkException
     */
    public function listAccounts(): array
    {
        [$rows, $truncated] = $this->paginate('list_accounts', '/accounts', [], self::ACCOUNTS_PAGE_SIZE);

        // A partial account list must never reach the pruning phase, which would read
        // the missing rows as accounts the user closed and delete them.
        if ($truncated) {
            throw new RedbarkRequestException('list_accounts returned a truncated account list', 'truncated');
        }

        return array_map(
            static fn (array $row): RedbarkAccountData => RedbarkAccountData::from($row),
            $rows,
        );
    }

    /**
     * @return list<RedbarkConnectionData>
     *
     * @throws RedbarkException
     */
    public function listConnections(): array
    {
        // Unpaginated: the endpoint accepts no limit/offset at all.
        $json = $this->handle($this->request()->get('/connections'));

        return array_map(
            static fn (array $row): RedbarkConnectionData => RedbarkConnectionData::from($row),
            $this->rowsFrom($json),
        );
    }

    /**
     * @param  list<string>  $accountIds
     * @return list<RedbarkBalanceData>
     *
     * @throws RedbarkException
     */
    public function getBalances(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $json = $this->handle($this->request()->get('/balances', [
            'accountIds' => implode(',', $accountIds),
        ]));

        return array_map(
            static fn (array $row): RedbarkBalanceData => RedbarkBalanceData::from($row),
            $this->rowsFrom($json),
        );
    }

    /**
     * @return list<RedbarkTransactionData>
     *
     * @throws RedbarkException
     */
    public function getTransactions(
        string $connectionId,
        string $accountId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includePending = false,
    ): array {
        $rows = $this->fetchTransactionsWindow(
            $connectionId,
            $accountId,
            $from,
            $to,
            $includePending,
            self::MAX_WINDOW_SPLITS,
        );

        return array_map(
            static fn (array $row): RedbarkTransactionData => RedbarkTransactionData::from($row),
            $rows,
        );
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(120)
            ->retry(3, 2000, static fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
    }

    /**
     * A truncated response means the server's row ceiling fired. Rather than recover
     * here, the flag is returned so each caller can decide: /accounts hard-fails,
     * /transactions halves its date window and retries.
     *
     * @param  array<string, mixed>  $query
     * @return array{0: list<array<string, mixed>>, 1: bool}
     *
     * @throws RedbarkException
     */
    private function paginate(string $operation, string $path, array $query, int $pageSize): array
    {
        $results = [];
        $offset = 0;
        $exhausted = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->request()->get($path, [
                ...$query,
                'limit' => $pageSize,
                'offset' => $offset,
            ]);

            $json = $this->handle($response);
            $data = $this->rowsFrom($json);
            $results = [...$results, ...$data];

            if (mb_strtolower((string) $response->header('X-Redbark-Truncated')) === 'true') {
                return [$results, true];
            }

            if (data_get($json, 'pagination.hasMore') !== true) {
                $exhausted = true;

                break;
            }

            // hasMore with nothing in it: the server stopped early and looping would spin.
            if ($data === []) {
                throw new RedbarkRequestException(
                    "$operation returned an empty page while reporting more results",
                    'truncated',
                );
            }

            // Advance by rows actually returned, never by the page size: a short page
            // would otherwise cause the next request to skip rows.
            $offset += count($data);
        }

        if (! $exhausted) {
            throw new RedbarkRequestException(
                "$operation exceeded ".self::MAX_PAGES.' pages without exhausting results',
                'too_many_pages',
            );
        }

        return [$results, false];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RedbarkException
     */
    private function fetchTransactionsWindow(
        string $connectionId,
        string $accountId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includePending,
        int $splitsLeft,
    ): array {
        $query = [
            'connectionId' => $connectionId,
            'accountId' => $accountId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];

        // The key is omitted entirely when pending rows are not wanted.
        if ($includePending) {
            $query['includePending'] = 'true';
        }

        [$rows, $truncated] = $this->paginate(
            'get_transactions',
            '/transactions',
            $query,
            self::TRANSACTIONS_PAGE_SIZE,
        );

        if (! $truncated) {
            return $rows;
        }

        if ($splitsLeft <= 0 || $from->greaterThanOrEqualTo($to)) {
            throw new RedbarkRequestException(
                'get_transactions hit the server row ceiling and the date window cannot be narrowed further',
                'truncated',
            );
        }

        $mid = $from->addDays(intdiv((int) $from->diffInDays($to), 2));

        $firstHalf = $this->fetchTransactionsWindow(
            $connectionId,
            $accountId,
            $from,
            $mid,
            $includePending,
            $splitsLeft - 1,
        );

        $secondHalf = $this->fetchTransactionsWindow(
            $connectionId,
            $accountId,
            $mid->addDay(),
            $to,
            $includePending,
            $splitsLeft - 1,
        );

        return $this->dedupeById([...$firstHalf, ...$secondHalf]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dedupeById(array $rows): array
    {
        $byKey = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $key = is_string($id) && $id !== '' ? $id : (string) json_encode($row);

            $byKey[$key] ??= $row;
        }

        return array_values($byKey);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(array $json): array
    {
        $data = $json['data'] ?? [];

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, is_array(...)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RedbarkException
     */
    private function handle(Response $response): array
    {
        $status = $response->status();

        if ($status === 200 || $status === 201) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $message = $this->errorMessageFrom($response);

        throw match (true) {
            $status === 400 => new RedbarkRequestException("Bad request: $message", 'bad_request'),
            $status === 401 => new RedbarkAuthenticationException('Invalid API key', 'unauthorized'),
            $status === 403 => new RedbarkAuthenticationException(
                'Access forbidden - your Redbark plan may not include API access',
                'access_forbidden',
            ),
            $status === 404 => new RedbarkRequestException('Resource not found', 'not_found'),
            // 410 shares the bad_request type on purpose: it is one of the two types
            // that make the job retry a rejected balances batch one account at a time.
            $status === 410 => new RedbarkRequestException("Endpoint requires an accountId: $message", 'bad_request'),
            $status === 429 => new RedbarkRateLimitException('Rate limit exceeded', 'rate_limited'),
            $status >= 500 => new RedbarkServerException("Redbark server error ($status)", 'server_error'),
            default => new RedbarkRequestException("Unexpected response $status: $message", 'unknown'),
        };
    }

    private function errorMessageFrom(Response $response): string
    {
        $message = data_get($response->json(), 'error.message');

        return is_string($message) && $message !== '' ? $message : 'no error message provided';
    }
}
