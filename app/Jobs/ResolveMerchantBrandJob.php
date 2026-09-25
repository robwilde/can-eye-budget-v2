<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Enums\MerchantBrandStatus;
use App\Models\MerchantBrand;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantBrands\ContextDevCreditBudget;
use App\Services\MerchantBrands\DescriptorGate;
use ContextDev\Core\Exceptions\ContextDevException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One paid Context.dev lookup for one of a user's merchant keys.
 *
 * Writes only the merchant_brands sidecar, never the transaction: merchant_name
 * feeds merchant_key, and re-keying rows would orphan rules and split recurring
 * clusters. Every guard runs before the credit is reserved, so a skipped lookup
 * costs nothing.
 */
final class ResolveMerchantBrandJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'enrichment';

    public const int RESOLVED_RETRY_DAYS = 180;

    public const int PARTIAL_RETRY_DAYS = 30;

    public const int UNRESOLVED_RETRY_DAYS = 14;

    /** Recent rows considered when picking one the gate will let through. */
    private const int CANDIDATE_ROWS = 10;

    /** The SDK already retries 408/409/429/5xx; a job retry would pay twice. */
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly User $user,
        public readonly string $merchantKey,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->user->id.':'.$this->merchantKey;
    }

    public function handle(DescriptorGate $gate, ContextDevCreditBudget $budget): void
    {
        if (! config('services.context_dev.enrichment_enabled')) {
            return;
        }

        $existing = $this->row();

        if ($existing?->blocksLookup()) {
            return;
        }

        $representative = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('merchant_key', $this->merchantKey)
            ->latest('post_date')
            ->latest('id')
            ->limit(self::CANDIDATE_ROWS)
            ->get()
            ->first(fn (Transaction $transaction): bool => $gate->allows($transaction));

        // Redacted by the gate: the raw description never leaves the app.
        $descriptor = $representative === null ? null : $gate->sendableDescriptor($representative);

        if ($representative === null || $descriptor === null) {
            return;
        }

        if (! $budget->tryReserve()) {
            Log::info('Context.dev daily credit cap reached; merchant lookup skipped', [
                'userId' => $this->user->id,
            ]);

            return;
        }

        try {
            $brand = app(ContextDevServiceContract::class)->brandFromTransaction(
                descriptor: $descriptor,
                // No country hint: the account being Australian says nothing about where the
                // merchant is ("US FRGN" rows), and country_gl constrains the match.
                countryCode: null,
                city: $this->hint($representative->enrich_data['location']['suburb'] ?? null),
                mcc: $this->hint($representative->enrich_data['redbark']['merchantCategoryCode'] ?? null),
            );
        } catch (ContextDevException $e) {
            // No row is written, so the next sweep may try again.
            Log::warning('Context.dev merchant lookup failed', [
                'userId' => $this->user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $this->store($brand, $descriptor);
    }

    private function store(?MerchantBrandData $brand, string $descriptor): void
    {
        $attributes = $brand === null
            ? [
                'status' => MerchantBrandStatus::Unresolved,
                'title' => null,
                'domain' => null,
                'logo_url' => null,
                'industry' => null,
                'subindustry' => null,
                'partial' => false,
                'resolved_at' => null,
                'retry_after' => now()->addDays(self::UNRESOLVED_RETRY_DAYS),
            ]
            : [
                'status' => MerchantBrandStatus::Resolved,
                'title' => $brand->title,
                'domain' => $brand->domain,
                'logo_url' => $brand->logoUrl,
                'industry' => $brand->industry,
                'subindustry' => $brand->subindustry,
                'partial' => $brand->partial,
                'resolved_at' => now(),
                'retry_after' => now()->addDays($brand->partial ? self::PARTIAL_RETRY_DAYS : self::RESOLVED_RETRY_DAYS),
            ];

        DB::transaction(function () use ($attributes, $descriptor): void {
            $row = MerchantBrand::query()
                ->where('user_id', $this->user->id)
                ->where('merchant_key', $this->merchantKey)
                ->lockForUpdate()
                ->first();

            // The user may have vetoed while the lookup was in flight; the veto wins.
            if ($row?->status === MerchantBrandStatus::Vetoed) {
                return;
            }

            ($row ?? new MerchantBrand(['user_id' => $this->user->id, 'merchant_key' => $this->merchantKey]))
                ->fill([...$attributes, 'source_descriptor' => mb_substr($descriptor, 0, 500)])
                ->save();
        });
    }

    private function row(): ?MerchantBrand
    {
        return MerchantBrand::query()
            ->where('user_id', $this->user->id)
            ->where('merchant_key', $this->merchantKey)
            ->first();
    }

    private function hint(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
