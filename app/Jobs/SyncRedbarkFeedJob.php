<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\RedbarkServiceContract;
use App\DTOs\RedbarkBalanceData;
use App\DTOs\RedbarkConnectionData;
use App\DTOs\RedbarkTransactionData;
use App\Enums\RedbarkFeedStatus;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Enums\TransactionDirection;
use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkException;
use App\Models\RedbarkAccount;
use App\Models\RedbarkFeed;
use App\Models\RedbarkSyncLog;
use App\Models\Transaction;
use App\Services\RedbarkClientFactory;
use App\Services\RedbarkTransactionMatcher;
use App\Services\TransactionIngestor;
use App\Support\RedbarkCurrency;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls one user's Redbark feed: connections, accounts, transactions, balances, then
 * applies the result to the app's accounts and transactions.
 *
 * The run is recorded in a RedbarkSyncLog, which is also the user-facing error surface —
 * per-phase failures are collected into $errors and the run continues, so one bad
 * account cannot cost the user every other account's data. Only an authentication
 * failure aborts: it means the key is dead and every subsequent call would fail too.
 */
final class SyncRedbarkFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int UNIQUE_FOR = 900;

    /** A settled pending row may post up to this many days after it appeared. */
    private const int PENDING_CLAIM_WINDOW_DAYS = 8;

    private const int BACKFILL_DAYS = 90;

    private const int INCREMENTAL_LOOKBACK_DAYS = 7;

    public int $tries = 5;

    public int $backoff = 30;

    /** A 90-day backfill across several accounts is many sequential HTTP calls. */
    public int $timeout = 600;

    public int $uniqueFor = self::UNIQUE_FOR;

    /** @var list<array{context: string, message: string}> */
    private array $errors = [];

    /**
     * Transactions adopted during this run, so each is claimed by at most one feed row.
     *
     * @var list<int>
     */
    private array $claimedTransactionIds = [];

    public function __construct(
        public readonly RedbarkFeed $feed,
        public readonly RefreshTrigger $trigger = RefreshTrigger::Scheduled,
    ) {}

    /**
     * Open the sync log *before* queueing, then dispatch.
     *
     * The log row is what the providers panel watches to decide whether to poll. Left to
     * handle() to create, there is a gap between dispatch and the worker picking the job
     * up in which the panel sees no open run, never starts polling, and so never shows
     * the result without a manual refresh. handle() reuses this row rather than opening
     * a second one.
     */
    public static function dispatchFor(RedbarkFeed $feed, RefreshTrigger $trigger): void
    {
        RedbarkSyncLog::firstOrCreate(
            ['redbark_feed_id' => $feed->id, 'status' => RefreshStatus::Pending],
            ['user_id' => $feed->user_id, 'trigger' => $trigger],
        );

        self::dispatch($feed, $trigger);
    }

    public function uniqueId(): int
    {
        return $this->feed->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("redbark-feed-{$this->feed->id}"),
        ];
    }

    /**
     * @throws RedbarkAuthenticationException
     */
    public function handle(
        RedbarkClientFactory $factory,
        TransactionIngestor $ingestor,
        RedbarkTransactionMatcher $matcher,
    ): void {
        $client = $factory->for($this->feed);
        $log = $this->openLog();

        $connections = $this->loadConnections($client, $log);
        $upstreamIds = $this->syncAccounts($client, $connections, $log);

        $this->feed->update([
            'pending_account_setup' => $this->feed->accounts()->needsSetup()->exists(),
        ]);

        $this->syncTransactions($client, $connections, $log);
        $balancesUpdated = $this->syncBalances($client, $connections, $upstreamIds, $log);
        [
            'created' => $created,
            'updated' => $updated,
            'matched' => $matched,
            'accounts' => $linkedCount,
        ] = $this->applyToAccounts($ingestor, $matcher);

        // Written even on partial failure: this is the incremental cursor, and the
        // 7-day lookback absorbs anything a failed run missed.
        $this->feed->update(['last_synced_at' => now()]);

        $log->update([
            'status' => $this->errors === [] ? RefreshStatus::Success : RefreshStatus::Failed,
            'accounts_synced' => $linkedCount,
            'transactions_created' => $created,
            // Rows adopted from existing CSV or manual history count as updated: the sync
            // linked them to the feed, it did not add anything the user did not already have.
            'transactions_updated' => $updated + $matched,
            'balances_updated' => $balancesUpdated,
            'errors' => $this->errors === [] ? null : $this->errors,
        ]);

        RunTransactionAnalysisJob::dispatch($this->feed->user);
    }

    public function failed(Throwable $exception): void
    {
        $this->openLogQuery()->first()?->update(['status' => RefreshStatus::Failed]);

        Log::error('SyncRedbarkFeedJob failed', [
            'feedId' => $this->feed->id,
            'userId' => $this->feed->user_id,
            'exception' => $exception,
        ]);
    }

    private static function toCents(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    /**
     * Reused across retries so a flapping run leaves one log row, not five.
     */
    private function openLog(): RedbarkSyncLog
    {
        return $this->openLogQuery()->first() ?? RedbarkSyncLog::create([
            'user_id' => $this->feed->user_id,
            'redbark_feed_id' => $this->feed->id,
            'trigger' => $this->trigger,
            'status' => RefreshStatus::Pending,
        ]);
    }

    /** @return Builder<RedbarkSyncLog> */
    private function openLogQuery(): Builder
    {
        return RedbarkSyncLog::query()
            ->where('redbark_feed_id', $this->feed->id)
            ->where('status', RefreshStatus::Pending)
            ->latest('id');
    }

    private function appendError(string $context, string $message): void
    {
        $this->errors[] = ['context' => $context, 'message' => $message];
    }

    /**
     * An authentication failure means the stored key is dead: flag the feed so the
     * settings panel prompts for a new one, close the log, and stop.
     */
    private function abortOnAuthFailure(RedbarkSyncLog $log, RedbarkAuthenticationException $exception): void
    {
        $this->feed->update(['status' => RedbarkFeedStatus::RequiresUpdate]);
        $this->appendError('authentication', $exception->getMessage());

        $log->update([
            'status' => RefreshStatus::Failed,
            'errors' => $this->errors,
        ]);
    }

    /**
     * D1. Connections are only metadata plus the category gate, so a non-auth failure
     * degrades to an empty map rather than aborting the run.
     *
     * @return array<string, RedbarkConnectionData>
     *
     * @throws RedbarkAuthenticationException
     */
    private function loadConnections(RedbarkServiceContract $client, RedbarkSyncLog $log): array
    {
        try {
            $connections = [];

            foreach ($client->listConnections() as $connection) {
                $connections[$connection->id] = $connection;
            }

            return $connections;
        } catch (RedbarkAuthenticationException $e) {
            $this->abortOnAuthFailure($log, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->appendError('connections', $e->getMessage());

            return [];
        }
    }

    /**
     * /transactions rejects a brokerage connection outright. A connection missing from
     * the map is allowed through: the metadata call may simply have failed.
     *
     * @param  array<string, RedbarkConnectionData>  $connections
     */
    private function isTransactable(array $connections, ?string $connectionId): bool
    {
        $connection = $connections[$connectionId] ?? null;

        if ($connection === null) {
            return true;
        }

        $category = mb_strtolower(mb_trim((string) $connection->category));

        return $category === '' || $category === 'banking' || $category === 'documents';
    }

    /**
     * /balances is stricter than /transactions: `documents` is not accepted here.
     *
     * @param  array<string, RedbarkConnectionData>  $connections
     */
    private function isBalanceEligible(array $connections, ?string $connectionId): bool
    {
        $connection = $connections[$connectionId] ?? null;

        if ($connection === null) {
            return true;
        }

        $category = mb_strtolower(mb_trim((string) $connection->category));

        return $category === '' || $category === 'banking';
    }

    /**
     * D2. Upstream accounts in, plus a prune of the ones that disappeared.
     *
     * @param  array<string, RedbarkConnectionData>  $connections
     * @return list<string>
     *
     * @throws RedbarkAuthenticationException
     */
    private function syncAccounts(RedbarkServiceContract $client, array $connections, RedbarkSyncLog $log): array
    {
        try {
            $rows = $client->listAccounts();
        } catch (RedbarkAuthenticationException $e) {
            $this->abortOnAuthFailure($log, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->appendError('accounts', $e->getMessage());

            return [];
        }

        $upstreamIds = [];

        foreach ($rows as $row) {
            if ($row->id === '' || $row->name === '') {
                continue;
            }

            if (! $this->isTransactable($connections, $row->connectionId)) {
                continue;
            }

            $connection = $connections[$row->connectionId] ?? null;
            $institution = $row->institutionName ?? $connection?->institutionName;

            RedbarkAccount::updateOrCreate(
                [
                    'redbark_feed_id' => $this->feed->id,
                    'redbark_account_id' => $row->id,
                ],
                [
                    'bank_connection_id' => $row->connectionId,
                    'name' => $institution === null || $institution === ''
                        ? $row->name
                        : "$institution - {$row->name}",
                    'account_number' => $row->accountNumber,
                    'currency' => RedbarkCurrency::normaliseOr($row->currency, 'AUD'),
                    'account_type' => $row->type,
                    'institution_name' => $institution,
                    // current_balance is deliberately absent: it comes from /balances,
                    // and writing a default here would clobber a good value.
                ],
            );

            $upstreamIds[] = $row->id;
        }

        // Guarded on a non-empty upstream list so a transient empty response can never
        // wipe the table. Linked rows are never pruned — the user's own accounts and
        // their history hang off them.
        if ($upstreamIds !== []) {
            RedbarkAccount::query()
                ->where('redbark_feed_id', $this->feed->id)
                ->whereNull('account_id')
                ->whereNotIn('redbark_account_id', $upstreamIds)
                ->delete();
        }

        return $upstreamIds;
    }

    /**
     * D4. Fetch each linked account's window and merge it into the stored snapshot.
     *
     * @param  array<string, RedbarkConnectionData>  $connections
     *
     * @throws RedbarkAuthenticationException
     */
    private function syncTransactions(RedbarkServiceContract $client, array $connections, RedbarkSyncLog $log): void
    {
        $includePending = (bool) config('services.redbark.include_pending');
        $end = CarbonImmutable::now();

        $accounts = $this->feed->accounts()
            ->linked()
            ->where('ignored', false)
            ->whereNotNull('bank_connection_id')
            ->get();

        foreach ($accounts as $redbarkAccount) {
            if (! $this->isTransactable($connections, $redbarkAccount->bank_connection_id)) {
                continue;
            }

            $start = $this->transactionStartDate($redbarkAccount);

            try {
                $fresh = $client->getTransactions(
                    (string) $redbarkAccount->bank_connection_id,
                    $redbarkAccount->redbark_account_id,
                    $start,
                    $end,
                    $includePending,
                );
            } catch (RedbarkAuthenticationException $e) {
                $this->abortOnAuthFailure($log, $e);

                throw $e;
            } catch (Throwable $e) {
                $this->appendError('transactions', "{$redbarkAccount->name}: {$e->getMessage()}");

                continue;
            }

            $freshRows = array_map(
                static fn (RedbarkTransactionData $row): array => get_object_vars($row),
                $fresh,
            );

            // An empty response leaves the snapshot alone. Merging it would read every
            // stored pending row as settled and drop the lot.
            if ($freshRows === []) {
                continue;
            }

            $redbarkAccount->update([
                'raw_transactions_payload' => $this->mergeTransactions(
                    $redbarkAccount->raw_transactions_payload ?? [],
                    $freshRows,
                    $start,
                ),
            ]);
        }
    }

    /**
     * A non-empty snapshot means this account has synced before, so only the last week
     * needs refetching to catch late-posting rows. Refetching the whole history every
     * run would hit the server's row ceiling on busy accounts.
     */
    private function transactionStartDate(RedbarkAccount $redbarkAccount): CarbonImmutable
    {
        $snapshot = $redbarkAccount->raw_transactions_payload;

        if ($snapshot !== null && $snapshot !== [] && $this->feed->last_synced_at !== null) {
            return $this->feed->last_synced_at->subDays(self::INCREMENTAL_LOOKBACK_DAYS)->startOfDay();
        }

        if ($redbarkAccount->sync_start_date !== null) {
            return $redbarkAccount->sync_start_date;
        }

        return CarbonImmutable::now()->subDays(self::BACKFILL_DAYS);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $fresh
     * @return list<array<string, mixed>>
     */
    private function mergeTransactions(array $existing, array $fresh, CarbonImmutable $windowStart): array
    {
        $freshKeys = [];

        foreach ($fresh as $row) {
            $freshKeys[$this->transactionKey($row)] = true;
        }

        $merged = [];

        foreach ($existing as $row) {
            if (! $this->withinWindow($row, $windowStart)) {
                continue;
            }

            // A stored pending row absent from the refetch has settled, possibly under a
            // new id. Keeping it would import it again alongside its posted twin.
            if ($this->isStalePending($row, $freshKeys)) {
                continue;
            }

            $merged[$this->transactionKey($row)] = $row;
        }

        foreach ($fresh as $row) {
            $merged[$this->transactionKey($row)] = $row;
        }

        return array_values($merged);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function transactionKey(array $row): string
    {
        $id = $row['id'] ?? null;

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return implode('-', [
            (string) ($row['date'] ?? ''),
            (string) ($row['amount'] ?? ''),
            (string) ($row['description'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function withinWindow(array $row, CarbonImmutable $windowStart): bool
    {
        $date = $this->parseDate($row['date'] ?? null);

        // An unparseable date is kept: dropping it would silently lose the row forever.
        return $date === null || $date->greaterThanOrEqualTo($windowStart->startOfDay());
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $freshKeys
     */
    private function isStalePending(array $row, array $freshKeys): bool
    {
        return ($row['status'] ?? null) === 'pending'
            && ! isset($freshKeys[$this->transactionKey($row)]);
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * D5. One batched call, with a per-account fallback because the endpoint rejects the
     * whole batch when any single id is unknown or non-banking.
     *
     * @param  array<string, RedbarkConnectionData>  $connections
     * @param  list<string>  $upstreamIds
     *
     * @throws RedbarkAuthenticationException
     */
    private function syncBalances(
        RedbarkServiceContract $client,
        array $connections,
        array $upstreamIds,
        RedbarkSyncLog $log,
    ): int {
        // Deliberately not restricted to linked accounts. The setup wizard shows a balance
        // beside each upstream account so the user can tell them apart, and creates the new
        // app account with it — neither works if the first sync, when nothing is linked yet,
        // skips balances. It costs nothing: /balances takes every id in one batched call.
        $eligible = $this->feed->accounts()
            ->where('ignored', false)
            ->get()
            ->filter(function (RedbarkAccount $redbarkAccount) use ($connections, $upstreamIds): bool {
                if ($redbarkAccount->redbark_account_id === '') {
                    return false;
                }

                if ($upstreamIds !== [] && ! in_array($redbarkAccount->redbark_account_id, $upstreamIds, true)) {
                    return false;
                }

                return $this->isBalanceEligible($connections, $redbarkAccount->bank_connection_id);
            });

        $ids = $eligible->pluck('redbark_account_id')->all();

        if ($ids === []) {
            return 0;
        }

        $balances = [];

        foreach ($this->fetchBalances($client, array_values($ids), $log) as $balance) {
            $balances[$balance->accountId] = $balance;
        }

        $updated = 0;

        foreach ($eligible as $redbarkAccount) {
            $balance = $balances[$redbarkAccount->redbark_account_id] ?? null;

            // A missing or unparseable balance leaves the previous value in place.
            // Writing 0 here would show the user an empty bank account.
            if ($balance === null || ! is_numeric($balance->currentBalance)) {
                continue;
            }

            $redbarkAccount->update([
                'current_balance' => self::toCents($balance->currentBalance),
                'currency' => RedbarkCurrency::normaliseOr($balance->currency, $redbarkAccount->currency),
            ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * @param  list<string>  $ids
     * @return list<RedbarkBalanceData>
     *
     * @throws RedbarkAuthenticationException
     */
    private function fetchBalances(RedbarkServiceContract $client, array $ids, RedbarkSyncLog $log): array
    {
        try {
            return $client->getBalances($ids);
        } catch (RedbarkAuthenticationException $e) {
            $this->abortOnAuthFailure($log, $e);

            throw $e;
        } catch (RedbarkException $e) {
            $recoverable = $e->errorType === 'not_found' || $e->errorType === 'bad_request';

            if (! $recoverable || count($ids) <= 1) {
                $this->appendError('balances', $e->getMessage());

                return [];
            }

            $this->appendError('balances', "Batched balances call rejected, retrying per account: {$e->getMessage()}");

            $balances = [];

            foreach ($ids as $id) {
                try {
                    $balances = [...$balances, ...$client->getBalances([$id])];
                } catch (RedbarkAuthenticationException $authException) {
                    $this->abortOnAuthFailure($log, $authException);

                    throw $authException;
                } catch (Throwable $accountException) {
                    $this->appendError('balances', "$id: {$accountException->getMessage()}");
                }
            }

            return $balances;
        } catch (Throwable $e) {
            $this->appendError('balances', $e->getMessage());

            return [];
        }
    }

    /**
     * D6. Balance first, then transactions, so an account's balance is already correct
     * before anything renders.
     *
     * @return array{created: int, updated: int, matched: int, accounts: int}
     */
    private function applyToAccounts(TransactionIngestor $ingestor, RedbarkTransactionMatcher $matcher): array
    {
        $created = 0;
        $updated = 0;
        $matched = 0;

        $accounts = $this->feed->accounts()
            ->linked()
            ->where('ignored', false)
            ->with('account')
            ->get();

        foreach ($accounts as $redbarkAccount) {
            $account = $redbarkAccount->account;

            if ($account === null) {
                continue;
            }

            if ($redbarkAccount->current_balance !== null) {
                $account->update([
                    'balance' => $redbarkAccount->current_balance,
                    'currency' => $redbarkAccount->currency,
                ]);
            }

            $rows = $redbarkAccount->raw_transactions_payload ?? [];
            $payloadIds = array_values(array_filter(
                array_map(static fn (array $row): mixed => $row['id'] ?? null, $rows),
                static fn (mixed $id): bool => is_string($id) && $id !== '',
            ));

            foreach ($rows as $row) {
                $result = $this->applyRow($ingestor, $matcher, $redbarkAccount, $row, $payloadIds);

                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                } elseif ($result === 'matched') {
                    $matched++;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated, 'matched' => $matched, 'accounts' => $accounts->count()];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $payloadIds
     * @return 'created'|'updated'|'matched'|'skipped'
     */
    private function applyRow(
        TransactionIngestor $ingestor,
        RedbarkTransactionMatcher $matcher,
        RedbarkAccount $redbarkAccount,
        array $row,
        array $payloadIds,
    ): string {
        $id = $row['id'] ?? null;
        $amount = $row['amount'] ?? null;

        if (! is_string($id) || $id === '' || ! is_numeric($amount)) {
            return 'skipped';
        }

        $postDate = $this->parseDate($row['date'] ?? null) ?? $this->parseDate($row['postDate'] ?? null);

        if ($postDate === null) {
            return 'skipped';
        }

        $amountCents = self::toCents((string) $amount);
        // Derived from the sign rather than the API's own direction field, so the two
        // can never disagree; the raw value is kept in enrich_data for audit.
        $direction = $amountCents < 0 ? TransactionDirection::Debit : TransactionDirection::Credit;
        $status = ($row['status'] ?? null) === 'pending' ? TransactionStatus::Pending : TransactionStatus::Posted;

        $description = $this->stringOrNull($row['description'] ?? null)
            ?? $this->stringOrNull($row['merchantName'] ?? null)
            ?? 'Transaction';

        $values = [
            'user_id' => $this->feed->user_id,
            'account_id' => $redbarkAccount->account_id,
            'amount' => $amountCents,
            'direction' => $direction,
            'description' => mb_substr($description, 0, 255),
            'post_date' => $postDate,
            'status' => $status,
            'source' => TransactionSource::Redbark,
            'merchant_name' => $this->stringOrNull($row['merchantName'] ?? null),
            'enrich_data' => [
                'redbark' => array_filter([
                    'category' => $row['category'] ?? null,
                    'merchantCategoryCode' => $row['merchantCategoryCode'] ?? null,
                    'direction' => $row['direction'] ?? null,
                    'accountName' => $row['accountName'] ?? null,
                    'valueDate' => $row['valueDate'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];

        // Trashed rows count: a folded fee must be recognised, never resurrected.
        $existing = Transaction::withTrashed()->where('redbark_id', $id)->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                // Folded fee: the parent purchase already carries this amount.
                if ($existing->folded_into_transaction_id !== null) {
                    return 'skipped';
                }

                $existing->fill($values);
                $existing->restore();

                return 'updated';
            }

            $existing->fill($values)->save();

            return 'updated';
        }

        $claimed = $this->claimSettledPending($redbarkAccount, $amountCents, $postDate, $status, $payloadIds);

        if ($claimed !== null) {
            // Keep the original post_date: the pending row is the same purchase, and
            // moving it would make the user's calendar jump.
            $claimed->fill([
                ...$values,
                'post_date' => $claimed->post_date,
                'redbark_id' => $id,
            ])->save();

            return 'updated';
        }

        // The user may already have this transaction from a CSV import or by hand. Adopt it
        // rather than import a second copy of the same purchase.
        $adopted = $matcher->findExisting(
            (int) $redbarkAccount->account_id,
            $amountCents,
            $postDate,
            $description,
            $this->claimedTransactionIds,
        );

        if ($adopted !== null) {
            $this->claimedTransactionIds[] = $adopted->id;

            // Stamp the id either way, so the next sync short-circuits on the lookup above.
            $adopted->forceFill(['redbark_id' => $id])->save();

            if ($adopted->trashed()) {
                return 'skipped';
            }

            // Keep what the user already has — description, date and category are theirs, and
            // the CSV narration is usually richer than the feed's masked one. Only add what
            // Redbark knows and the existing row does not.
            $adopted->fill([
                'source' => TransactionSource::Redbark,
                'status' => $status,
                'enrich_data' => $values['enrich_data'],
                'merchant_name' => $adopted->merchant_name ?? $values['merchant_name'],
            ])->save();

            return 'matched';
        }

        // New rows must go through the ingestor: it is the single funnel that reconciles
        // against planned transactions and emits TransactionEntered/TransactionReconciled.
        $ingestor->ingest(new Transaction(['redbark_id' => $id, ...$values]));

        return 'created';
    }

    /**
     * The pending→posted replacement for the case where the bank issues a new id on
     * settlement. Same-id settlement needs nothing special: the redbark_id lookup finds
     * the row and the fill flips its status.
     *
     * Candidates still present in the current payload are excluded — those rows are
     * genuinely still pending, and claiming one would strand its own payload row into a
     * duplicate on the next pass.
     *
     * @param  list<string>  $payloadIds
     */
    private function claimSettledPending(
        RedbarkAccount $redbarkAccount,
        int $amountCents,
        CarbonImmutable $postDate,
        TransactionStatus $status,
        array $payloadIds,
    ): ?Transaction {
        if ($status !== TransactionStatus::Posted) {
            return null;
        }

        return Transaction::query()
            ->where('account_id', $redbarkAccount->account_id)
            ->where('source', TransactionSource::Redbark)
            ->where('status', TransactionStatus::Pending)
            ->where('amount', $amountCents)
            ->whereBetween('post_date', [
                $postDate->subDays(self::PENDING_CLAIM_WINDOW_DAYS)->toDateString(),
                $postDate->toDateString(),
            ])
            ->when($payloadIds !== [], fn (Builder $query): Builder => $query->where(
                fn (Builder $inner): Builder => $inner
                    ->whereNull('redbark_id')
                    ->orWhereNotIn('redbark_id', $payloadIds),
            ))
            ->latest('post_date')
            ->first();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
