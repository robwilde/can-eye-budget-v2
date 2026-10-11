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
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Reads bank data from Redbark over the SimpleFIN v1 protocol, presenting it through the
 * same contract as the REST client so SyncRedbarkFeedJob needs no second code path.
 *
 * SimpleFIN has one aggregate GET /accounts, not separate accounts, balances and
 * transactions endpoints. The unfiltered balances-only read is made once per instance and
 * serves connections, accounts and balances; transactions are read one account at a time
 * with an `account` filter, because a filtered request that cannot serve an account fails
 * outright rather than returning a partial set the job might read as a closed account.
 *
 * SimpleFIN has no connection id. The organisation id (falling back to its domain) is used
 * as a local grouping key only; it is never sent to the server.
 */
final class SimpleFinService implements RedbarkServiceContract
{
    public const int TRANSACTIONS_PER_ACCOUNT_CAP = 2000;

    public const int MAX_WINDOW_SPLITS = 6;

    public const int NOTICE_MAX_LENGTH = 200;

    private const string DEFAULT_CONNECTION_ID = 'simplefin';

    /** @var list<array<string, mixed>>|null */
    private ?array $discovered = null;

    /** @var list<string> */
    private array $notices = [];

    public function __construct(
        #[SensitiveParameter]
        private readonly string $accessUrl,
        private readonly int $timeoutSeconds = 120,
        private readonly int $maxAttempts = 3,
    ) {}

    /**
     * @return list<RedbarkConnectionData>
     *
     * @throws RedbarkException
     */
    public function listConnections(): array
    {
        $connections = [];

        foreach ($this->discover() as $account) {
            $id = self::connectionIdOf($account);

            $connections[$id] ??= RedbarkConnectionData::from([
                'id' => $id,
                'category' => 'banking',
                'institutionName' => self::stringOrNull(data_get($account, 'org.name')),
                'status' => 'active',
            ]);
        }

        return array_values($connections);
    }

    /**
     * @return list<RedbarkAccountData>
     *
     * @throws RedbarkException
     */
    public function listAccounts(): array
    {
        return array_map(
            static fn (array $account): RedbarkAccountData => RedbarkAccountData::from([
                'id' => (string) ($account['id'] ?? ''),
                'connectionId' => self::connectionIdOf($account),
                'provider' => 'simplefin',
                'name' => (string) ($account['name'] ?? ''),
                'institutionName' => self::stringOrNull(data_get($account, 'org.name')),
                'accountNumber' => self::stringOrNull(data_get($account, 'extra.account_number')),
                'currency' => self::stringOrNull($account['currency'] ?? null),
            ]),
            $this->discover(),
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
        $balances = [];

        foreach ($this->discover() as $account) {
            if (! in_array((string) ($account['id'] ?? ''), $accountIds, true)) {
                continue;
            }

            $balances[] = RedbarkBalanceData::from([
                'accountId' => (string) $account['id'],
                'currentBalance' => self::stringOrNull($account['balance'] ?? null),
                'availableBalance' => self::stringOrNull($account['available-balance'] ?? null),
                'currency' => self::stringOrNull($account['currency'] ?? null),
            ]);
        }

        return $balances;
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
        $rows = $this->fetchTransactionsWindow($accountId, $from, $to, $includePending, self::MAX_WINDOW_SPLITS);

        return array_values(array_filter(array_map(
            fn (array $row): ?RedbarkTransactionData => $this->transactionFrom($accountId, $row),
            $rows,
        )));
    }

    /**
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private static function connectionIdOf(array $account): string
    {
        $id = data_get($account, 'org.id') ?? data_get($account, 'org.domain');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : self::DEFAULT_CONNECTION_ID;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RedbarkException
     */
    private function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = $this->fetchAccounts(['balances-only' => 1]);

        return $this->discovered;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RedbarkException
     */
    private function fetchTransactionsWindow(
        string $accountId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $includePending,
        int $splitsLeft,
    ): array {
        $query = [
            'account' => $accountId,
            'start-date' => $from->getTimestamp(),
            'end-date' => $to->getTimestamp(),
        ];

        if ($includePending) {
            $query['pending'] = 1;
        }

        $rows = [];
        $payload = $this->fetchPayload($query);

        foreach ($payload['accounts'] as $account) {
            if ((string) ($account['id'] ?? '') === $accountId) {
                $rows = array_values(array_filter((array) ($account['transactions'] ?? []), is_array(...)));
            }
        }

        if ($rows === [] && $payload['errors'] !== []) {
            throw new RedbarkRequestException('SimpleFIN reported a problem reading this account', 'provider_error');
        }

        if (count($rows) < self::TRANSACTIONS_PER_ACCOUNT_CAP) {
            return $rows;
        }

        $span = $to->getTimestamp() - $from->getTimestamp();

        if ($splitsLeft <= 0 || $span < 2) {
            throw new RedbarkRequestException(
                'get_transactions hit the per-account row cap and the date window cannot be narrowed further',
                'truncated',
            );
        }

        $mid = $from->addSeconds(intdiv($span, 2));

        return $this->dedupeById([
            ...$this->fetchTransactionsWindow($accountId, $from, $mid, $includePending, $splitsLeft - 1),
            ...$this->fetchTransactionsWindow($accountId, $mid, $to, $includePending, $splitsLeft - 1),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function transactionFrom(string $accountId, array $row): ?RedbarkTransactionData
    {
        $id = self::stringOrNull($row['id'] ?? null);
        $amount = self::stringOrNull($row['amount'] ?? null);
        $pending = filter_var($row['pending'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $posted = (int) ($row['posted'] ?? 0);
        $transacted = (int) ($row['transacted_at'] ?? 0);
        $epoch = $pending ? ($transacted > 0 ? $transacted : $posted) : ($posted > 0 ? $posted : $transacted);

        if ($id === null || $amount === null || $epoch <= 0) {
            return null;
        }

        $date = CarbonImmutable::createFromTimestamp($epoch, (string) config('app.timezone'))->toDateString();

        $description = mb_trim((string) ($row['description'] ?? ''));

        if ($description === '') {
            $description = mb_trim((string) ($row['payee'] ?? $row['memo'] ?? ''));
        }

        return RedbarkTransactionData::from([
            'id' => $id,
            'accountId' => $accountId,
            'status' => $pending ? 'pending' : 'posted',
            'date' => $date,
            'postDate' => $date,
            'description' => $description,
            'merchantName' => self::stringOrNull($row['payee'] ?? null),
            'amount' => $amount,
            'direction' => str_starts_with($amount, '-') ? 'debit' : 'credit',
        ]);
    }

    /**
     * @param  array<string, int|string>  $query
     * @return list<array<string, mixed>>
     *
     * @throws RedbarkException
     */
    private function fetchAccounts(array $query): array
    {
        return $this->fetchPayload($query)['accounts'];
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array{accounts: list<array<string, mixed>>, errors: list<string>}
     *
     * @throws RedbarkException
     */
    private function fetchPayload(array $query): array
    {
        $json = $this->handle($this->get('/accounts', $query));
        $errors = array_values(array_filter((array) ($json['errors'] ?? []), is_string(...)));

        foreach ($errors as $error) {
            $this->recordNotice($error);
        }

        return [
            'accounts' => array_values(array_filter((array) ($json['accounts'] ?? []), is_array(...))),
            'errors' => $errors,
        ];
    }

    private function recordNotice(mixed $error): void
    {
        if (! is_string($error)) {
            return;
        }

        $clean = mb_trim((string) preg_replace('/[\s\p{C}]+/u', ' ', strip_tags($error)));

        if ($clean === '') {
            return;
        }

        $clean = mb_substr($clean, 0, self::NOTICE_MAX_LENGTH);

        if (! in_array($clean, $this->notices, true)) {
            $this->notices[] = $clean;
        }
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
     * @throws RedbarkRequestException
     */
    private function request(): PendingRequest
    {
        $parts = parse_url($this->accessUrl);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) {
            throw new RedbarkRequestException('Stored SimpleFIN access URL is malformed', 'bad_request');
        }

        $base = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .mb_rtrim($parts['path'] ?? '', '/');

        return Http::baseUrl($base)
            ->withBasicAuth(rawurldecode($parts['user']), rawurldecode($parts['pass']))
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->retry($this->maxAttempts, 2000, static fn (Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->status() >= 500)), throw: false);
    }

    /**
     * @param  array<string, int|string>  $query
     *
     * @throws RedbarkException
     */
    private function get(string $path, array $query): Response
    {
        try {
            return $this->request()->get($path, $query);
        } catch (ConnectionException $e) {
            throw new RedbarkRequestException(
                'Connection failed: '.$e->getMessage(),
                errorType: 'connection_failed',
                previous: $e,
            );
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RedbarkException
     */
    private function handle(Response $response): array
    {
        $status = $response->status();

        if ($status === 200) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        throw match (true) {
            $status === 401 => new RedbarkAuthenticationException('SimpleFIN access was rejected', 'unauthorized'),
            $status === 402 => new RedbarkAuthenticationException('SimpleFIN access requires an active Redbark plan', 'payment_required'),
            $status === 403 => new RedbarkAuthenticationException('SimpleFIN access was revoked or the plan no longer includes it', 'access_forbidden'),
            $status === 429 => new RedbarkRateLimitException('Rate limit exceeded', 'rate_limited'),
            $status >= 500 => new RedbarkServerException("Redbark SimpleFIN server error ($status)", 'server_error'),
            $status === 400 => new RedbarkRequestException('Bad request', 'bad_request'),
            default => new RedbarkRequestException("Unexpected response $status", 'unknown'),
        };
    }
}
