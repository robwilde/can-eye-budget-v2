<?php

declare(strict_types=1);

namespace App\Services\Transactions;

use App\Enums\StaleVersion;
use App\Models\Transaction;
use Closure;

/**
 * How a caller wants a TransactionWriter write done. The defaults are the REST API's: only a manual row may
 * change its amount, direction, date or description, and a newer version is followed.
 */
final readonly class TransactionWriteOptions
{
    /**
     * @param  bool  $allowImportedCoreEdits  imported CSV and PDF rows may change amount, direction, date and account (a bank-feed row never may)
     * @param  StaleVersion  $stale  what to do when the row was versioned since the caller read it
     * @param  string|null  $idempotencyKey  a create with a key already used returns the row the key made
     * @param  int  $attempts  how often the write is tried again after a deadlock or lock timeout
     * @param  Closure(Transaction): void|null  $guard  the caller's own checks, run on the locked row before anything is written
     * @param  Closure(Transaction): void|null  $within  more writes that must commit or roll back with this one, run with the written row
     */
    public function __construct(
        public bool $allowImportedCoreEdits = false,
        public StaleVersion $stale = StaleVersion::Follow,
        public ?string $idempotencyKey = null,
        public int $attempts = 1,
        public ?Closure $guard = null,
        public ?Closure $within = null,
    ) {}
}
