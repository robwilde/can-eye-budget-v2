<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Makes a create safe to submit twice. A caller holds one key per form submission; the first
 * create under a key inserts, and a repeat under the same key returns that first row instead.
 * Keys are scoped to the user and matched against soft-deleted rows too, so a repeat of a
 * submission whose row has since been deleted does not bring it back. A repeat racing the
 * first insert is settled by the (user_id, idempotency_key) unique index.
 */
final readonly class TransactionIdempotency
{
    public function __construct(private TransactionIngestor $ingestor) {}

    public function existing(int $userId, string $key): ?Transaction
    {
        return Transaction::query()
            ->withTrashed()
            ->where('user_id', $userId)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function ingestOnce(Transaction $transaction, string $key): Transaction
    {
        $existing = $this->existing($transaction->user_id, $key);

        if ($existing instanceof Transaction) {
            return $existing;
        }

        $transaction->idempotency_key = $key;

        try {
            return $this->ingestor->ingest($transaction);
        } catch (UniqueConstraintViolationException $e) {
            return $this->existing($transaction->user_id, $key) ?? throw $e;
        }
    }
}
