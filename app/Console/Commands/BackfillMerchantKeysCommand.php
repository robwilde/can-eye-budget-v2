<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MerchantBrandStatus;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Support\Recurring\MerchantSignature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Populates transactions.merchant_key for rows written before the column
 * existed, and doubles as the recompute path when MerchantSignature changes.
 *
 * Without the recompute mode, any future tweak to the signature rules would
 * leave historical rows keyed under the old algorithm, silently splitting one
 * payee into two clusters. Re-running is always safe: the command only writes
 * when the derived key differs from the stored one.
 *
 * Recompute also re-keys merchant_brands, which is keyed on (user_id,
 * merchant_key) and would otherwise be orphaned by the new keys. Brand keys are
 * re-derived from the stored key itself (MerchantSignature::for() maps a key to
 * itself), so vetoes with no transaction behind them move too. When several of
 * a user's rows land on one key, one survives: resolved over vetoed over
 * unresolved, since a paid lookup and a user's veto outrank a failed lookup.
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

        if ($recompute) {
            $this->rekeyBrands($dryRun);
        }

        return self::SUCCESS;
    }

    private function rekeyBrands(bool $dryRun): void
    {
        $rekeyed = 0;
        $merged = 0;

        $userIds = MerchantBrand::query()->distinct()->orderBy('user_id')->pluck('user_id');

        foreach ($userIds as $userId) {
            DB::transaction(function () use ($userId, $dryRun, &$rekeyed, &$merged): void {
                // Read and merge under one lock. The locking read on the
                // (user_id, merchant_key) index also holds off a concurrent veto or
                // lookup inserting a key for this user until the merge commits, so
                // neither can claim a target key or resurrect a loser mid-merge.
                $groups = MerchantBrand::query()
                    ->where('user_id', $userId)
                    ->where('merchant_key', '!=', Transaction::UNKNOWN_MERCHANT_KEY)
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(fn (MerchantBrand $brand): string => MerchantSignature::for($brand->merchant_key));

                foreach ($groups as $key => $brands) {
                    $key = (string) $key;
                    $winner = $this->survivor($brands);
                    $losers = $brands->reject(fn (MerchantBrand $brand): bool => $brand->is($winner));
                    $moves = $winner->merchant_key !== $key;

                    $merged += $losers->count();
                    $rekeyed += (int) $moves;

                    if ($dryRun) {
                        continue;
                    }

                    // Losers go first: one of them may hold the target key already.
                    if ($losers->isNotEmpty()) {
                        MerchantBrand::query()->whereKey($losers->modelKeys())->delete();
                    }

                    if ($moves) {
                        // Bookkeeping, not a brand change: updated_at feeds the
                        // pending-lookup poll on the transactions list.
                        $winner->timestamps = false;
                        $winner->merchant_key = $key;
                        $winner->saveQuietly();
                    }
                }
            });
        }

        $this->info(sprintf(
            '%s %d merchant brand(s), merging away %d.',
            $dryRun ? 'Would rekey' : 'Rekeyed',
            $rekeyed,
            $merged,
        ));
    }

    /**
     * @param  Collection<int, MerchantBrand>  $brands
     */
    private function survivor(Collection $brands): MerchantBrand
    {
        /** @var MerchantBrand */
        return $brands->sortBy([
            fn (MerchantBrand $a, MerchantBrand $b): int => $this->rank($a->status) <=> $this->rank($b->status),
            fn (MerchantBrand $a, MerchantBrand $b): int => $a->partial <=> $b->partial,
            fn (MerchantBrand $a, MerchantBrand $b): int => $b->updated_at <=> $a->updated_at,
            fn (MerchantBrand $a, MerchantBrand $b): int => $a->id <=> $b->id,
        ])->first();
    }

    private function rank(MerchantBrandStatus $status): int
    {
        return match ($status) {
            MerchantBrandStatus::Resolved => 0,
            MerchantBrandStatus::Vetoed => 1,
            MerchantBrandStatus::Unresolved => 2,
        };
    }
}
