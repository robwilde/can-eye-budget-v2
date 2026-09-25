<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TransactionDirection;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantBrands\ContextDevCreditBudget;
use App\Services\MerchantBrands\RepresentativeTransaction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After an import, queue a brand lookup for each of the user's merchant keys that
 * has earned one: it recurs, it is a debit that is not a transfer, the gate lets
 * a recent row through, and it has no vetoed or still-fresh sidecar row.
 * One-off descriptors never spend credits, and keys the gate refuses never take
 * a slot.
 *
 * Most-frequent keys go first and the batch is sized to what is left of today's
 * credit budget, so a large first import cannot flood the queue with lookups
 * that would only be refused by the cap.
 */
final class EnrichMerchantBrandsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MIN_OCCURRENCES = 2;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly User $user,
    ) {
        $this->onQueue(ResolveMerchantBrandJob::QUEUE);
    }

    public function uniqueId(): int
    {
        return $this->user->id;
    }

    public function handle(ContextDevCreditBudget $budget, RepresentativeTransaction $representatives): void
    {
        if (! config('services.context_dev.enrichment_enabled')) {
            return;
        }

        $slots = intdiv($budget->remaining(), ContextDevCreditBudget::BRAND_LOOKUP_CREDITS);

        if ($slots === 0) {
            return;
        }

        $blocked = MerchantBrand::query()
            ->select('merchant_key')
            ->where('user_id', $this->user->id)
            ->blockingLookup();

        // No limit: one user's recurring keys are bounded, and gate-refused keys
        // must be skipped without costing a slot.
        $keys = Transaction::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('merchant_key')
            ->where('merchant_key', '!=', Transaction::UNKNOWN_MERCHANT_KEY)
            ->whereNull('transfer_pair_id')
            ->where('direction', TransactionDirection::Debit)
            ->whereNotIn('merchant_key', $blocked)
            ->groupBy('merchant_key')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_OCCURRENCES])
            ->orderByRaw('COUNT(*) DESC')
            ->pluck('merchant_key');

        $dispatched = 0;

        foreach ($keys as $key) {
            if ($dispatched === $slots) {
                break;
            }

            // The gate refuses every recent row: no slot taken.
            if ($representatives->for($this->user, $key) === null) {
                continue;
            }

            ResolveMerchantBrandJob::dispatch($this->user, $key);
            $dispatched++;
        }
    }
}
