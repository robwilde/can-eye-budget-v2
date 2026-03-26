<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\BasiqServiceContract;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $backoff = 5;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly User $user,
        public readonly ?string $jobId = null,
    ) {}

    public function uniqueId(): int
    {
        return $this->user->id;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping("sync-user-{$this->user->id}"),
        ];
    }

    /**
     * @throws ConnectionException|RequestException
     */
    public function handle(BasiqServiceContract $basiqService): void
    {
        try {
            $this->process($basiqService);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                Log::error('Basiq user not found (404). Clearing stale basiq_user_id.', [
                    'userId' => $this->user->id,
                    'basiqUserId' => $this->user->basiq_user_id,
                ]);

                $this->user->update(['basiq_user_id' => null]);
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SyncTransactionsJob failed', [
            'userId' => $this->user->id,
            'exception' => $exception,
        ]);
    }

    private static function toCents(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }

    private static function toCentsOrNull(?string $amount): ?int
    {
        return $amount !== null ? self::toCents($amount) : null;
    }

    /**
     * @throws ConnectionException|RequestException
     */
    private function process(BasiqServiceContract $basiqService): void
    {
        if ($this->jobId !== null) {
            $job = $basiqService->getJob($this->jobId);

            if ($job->status === 'pending') {
                $this->release($this->backoff);

                return;
            }

            if ($job->status === 'failed') {
                Log::warning('Basiq job failed', ['jobId' => $this->jobId, 'userId' => $this->user->id]);

                return;
            }
        }

        $accountMap = $this->syncAccounts($basiqService);
        $this->syncTransactions($basiqService, $accountMap);
    }

    /**
     * @return Collection<string, int>
     *
     * @throws ConnectionException|RequestException
     */
    private function syncAccounts(BasiqServiceContract $basiqService): Collection
    {
        $basiqAccounts = $basiqService->getAccounts($this->user->basiq_user_id);

        foreach ($basiqAccounts as $dto) {
            Account::updateOrCreate(
                ['basiq_account_id' => $dto->id],
                [
                    'user_id' => $this->user->id,
                    'name' => $dto->name,
                    'type' => $dto->type ?? 'transaction',
                    'institution' => $dto->institution,
                    'currency' => $dto->currency,
                    'balance' => self::toCents($dto->balance ?? '0'),
                    'credit_limit' => self::toCentsOrNull($dto->creditLimit),
                    'available_funds' => self::toCentsOrNull($dto->availableFunds),
                    'status' => $dto->status ?? 'active',
                ],
            );
        }

        Log::info('Accounts synced', ['userId' => $this->user->id, 'count' => $basiqAccounts->count()]);

        return Account::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('basiq_account_id')
            ->pluck('id', 'basiq_account_id');
    }

    /**
     * @param  Collection<string, int>  $accountMap
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private function syncTransactions(BasiqServiceContract $basiqService, Collection $accountMap): void
    {
        $filter = null;
        if ($this->user->last_synced_at) {
            $filter = ["transaction.postDate.gt('{$this->user->last_synced_at->format('Y-m-d')}')"];
        }

        $transactions = $basiqService->paginateTransactions($this->user->basiq_user_id, $filter);
        $created = 0;
        $updated = 0;

        foreach ($transactions as $dto) {
            $accountId = $accountMap->get($dto->account);

            if ($accountId === null) {
                continue;
            }

            if ($dto->postDate === null) {
                continue;
            }

            $wasRecentlyCreated = Transaction::updateOrCreate(
                ['basiq_id' => $dto->id],
                [
                    'user_id' => $this->user->id,
                    'account_id' => $accountId,
                    'amount' => self::toCents($dto->amount),
                    'direction' => $dto->direction,
                    'description' => $dto->description ?? '',
                    'post_date' => $dto->postDate,
                    'transaction_date' => $dto->transactionDate,
                    'status' => $dto->status ?? 'posted',
                    'basiq_account_id' => $dto->account,
                    'merchant_name' => $dto->merchant,
                    'anzsic_code' => $dto->anzsic,
                    'enrich_data' => $dto->enrichData,
                ],
            )->wasRecentlyCreated;

            $wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->user->update(['last_synced_at' => now()]);

        Log::info('Transactions synced', [
            'userId' => $this->user->id,
            'created' => $created,
            'updated' => $updated,
        ]);
    }
}
