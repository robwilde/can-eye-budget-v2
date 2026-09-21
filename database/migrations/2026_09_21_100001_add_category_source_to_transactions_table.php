<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records who chose each transaction's category, so a retroactive rule can
 * fill gaps without overwriting a category the user set by hand.
 *
 * Deliberately nullable with no database default. The invariant is
 * "category_source IS NULL exactly when category_id IS NULL" — a default of
 * 'manual' would claim a human chose a category on rows that have none.
 *
 * Existing categorised rows are backfilled to 'manual', the conservative
 * reading: they were either typed by the user or already accepted by them, so
 * rules must not touch them. Marking them 'rule' instead would leave the whole
 * back catalogue open to being silently rewritten by the first bulk rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('category_source')->nullable()->after('category_id');
        });

        DB::table('transactions')
            ->whereNotNull('category_id')
            ->update(['category_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('category_source');
        });
    }
};
