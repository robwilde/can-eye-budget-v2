<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TransactionSource;
use App\Enums\TransactionStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkAccount;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class ExpireRedbarkHoldsCommand extends Command
{
    protected $signature = 'app:expire-redbark-holds {--days= : Override the configured TTL} {--dry-run}';

    protected $description = 'Void Redbark holds that never received a settlement row';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('services.redbark.hold_ttl_days'));

        if ($days <= SyncRedbarkFeedJob::PENDING_CLAIM_WINDOW_DAYS) {
            $this->error(sprintf(
                'TTL (%d days) must exceed PENDING_CLAIM_WINDOW_DAYS (%d days) or the command would void holds that could still legitimately settle.',
                $days,
                SyncRedbarkFeedJob::PENDING_CLAIM_WINDOW_DAYS,
            ));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = CarbonImmutable::now()->subDays($days);
        $expired = 0;
        $reverted = 0;

        RedbarkAccount::query()->linked()->chunkById(100, function ($redbarkAccounts) use ($cutoff, $dryRun, &$expired, &$reverted): void {
            foreach ($redbarkAccounts as $redbarkAccount) {
                $rows = $redbarkAccount->raw_transactions_payload ?? [];
                $payloadIds = array_values(array_filter(
                    array_map(static fn (array $row): mixed => $row['id'] ?? null, $rows),
                    static fn (mixed $id): bool => is_string($id) && $id !== '',
                ));

                Transaction::query()
                    ->where('account_id', $redbarkAccount->account_id)
                    ->whereNotNull('redbark_id')
                    ->where('status', TransactionStatus::Pending)
                    ->where('post_date', '<', $cutoff->toDateString())
                    // Still in the payload means the bank is still reporting it as
                    // uncleared: that is a live hold, not an orphan, however old it is.
                    ->when($payloadIds !== [], fn (Builder $query): Builder => $query->whereNotIn('redbark_id', $payloadIds))
                    ->chunkById(500, function ($transactions) use ($dryRun, &$expired, &$reverted): void {
                        foreach ($transactions as $transaction) {
                            if ($transaction->source === TransactionSource::Redbark) {
                                // Feed-created: the authorisation was released and the
                                // money never left.
                                $dryRun || $transaction->delete();
                                $expired++;

                                continue;
                            }

                            // Adopted CSV or manual row. The user's own record stands;
                            // only the Pending status the feed stamped on it is wrong.
                            // Deleting it would destroy their data.
                            $dryRun || $transaction->update(['status' => TransactionStatus::Posted]);
                            $reverted++;
                        }
                    });
            }
        });

        $this->info(sprintf(
            '%sExpired %d orphaned hold(s), reverted %d adopted row(s) to posted.',
            $dryRun ? 'Dry run: ' : '',
            $expired,
            $reverted,
        ));

        return self::SUCCESS;
    }
}
