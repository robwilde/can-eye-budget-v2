<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Populates transactions.merchant_key for rows written before the column
 * existed, and doubles as the recompute path when MerchantSignature changes.
 *
 * Without the recompute mode, any future tweak to the signature rules would
 * leave historical rows keyed under the old algorithm, silently splitting one
 * payee into two clusters. Re-running is always safe: the command only writes
 * when the derived key differs from the stored one.
 */
final class BackfillMerchantKeysCommand extends Command
{
    protected $signature = 'app:backfill-merchant-keys
        {--recompute : Recompute every row, not just rows with no key}
        {--chunk=500 : Rows loaded per batch}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Populate or recompute the persisted merchant_key on transactions';

    public function handle(): int
    {
        $recompute = (bool) $this->option('recompute');
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $scanned = 0;
        $changed = 0;

        Transaction::query()
            ->withTrashed()
            ->when(! $recompute, fn (Builder $q): Builder => $q->whereNull('merchant_key'))
            ->chunkById($chunk, function ($transactions) use ($dryRun, &$scanned, &$changed): void {
                foreach ($transactions as $transaction) {
                    $scanned++;
                    $derived = $transaction->resolveMerchantKey();

                    if ($transaction->merchant_key === $derived) {
                        continue;
                    }

                    $changed++;

                    if ($dryRun) {
                        continue;
                    }

                    // saveQuietly: a backfill is bookkeeping, not a user action.
                    // Firing model events here would replay listeners against
                    // historical rows that have already been processed once.
                    $transaction->merchant_key = $derived;
                    $transaction->saveQuietly();
                }
            });

        $this->info(sprintf(
            '%s %d of %d transaction(s).',
            $dryRun ? 'Would update' : 'Updated',
            $changed,
            $scanned,
        ));

        return self::SUCCESS;
    }
}
