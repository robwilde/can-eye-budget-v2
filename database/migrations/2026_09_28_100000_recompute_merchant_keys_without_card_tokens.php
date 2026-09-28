<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * MerchantSignature now drops a leading card network and a trailing FRGN
 * (#480), so every stored transactions.merchant_key and merchant_brands key
 * written under the old rule is stale. Running the recompute here makes it part
 * of the deploy (the web container migrates on boot) instead of an ops step
 * that can be forgotten while the new code clusters fresh rows apart from old
 * ones.
 *
 * The command re-derives keys from current rules, so replaying this on a fresh
 * or lagging database is harmless: empty tables do nothing, current rows are
 * skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('app:backfill-merchant-keys', ['--recompute' => true]);
    }

    /**
     * Irreversible: merged merchant_brands rows are deleted, and the old keys are
     * not recoverable from the survivors.
     */
    public function down(): void {}
};
