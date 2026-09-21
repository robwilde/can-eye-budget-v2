<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the persisted merchant signature used to cluster transactions by payee.
 *
 * MerchantSignature::for() already normalises a description into a stable payee
 * key, but it is PHP and cannot run inside a SQL GROUP BY. Persisting the value
 * turns "show me every AFTERPAY row" into an ordinary indexed aggregate instead
 * of loading the whole result set into memory on every render.
 *
 * The composite index is ordered user_id, category_id, merchant_key because the
 * driving query is "uncategorised clusters for this user": user_id is always an
 * equality match, category_id is the IS NULL filter, and merchant_key is the
 * grouping column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('merchant_key')->nullable()->after('merchant_name');

            $table->index(['user_id', 'category_id', 'merchant_key'], 'transactions_user_category_merchant_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_user_category_merchant_index');
            $table->dropColumn('merchant_key');
        });
    }
};
