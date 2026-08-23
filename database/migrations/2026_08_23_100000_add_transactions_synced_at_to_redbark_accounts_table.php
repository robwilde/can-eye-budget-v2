<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redbark_accounts', function (Blueprint $table): void {
            $table->timestamp('transactions_synced_at')->nullable()->after('sync_start_date');
        });

        // Existing accounts already hold a snapshot. Without seeding their cursor from the
        // feed-level watermark they would all read as never-synced and refetch 90 days on
        // the first run after deploy.
        foreach (DB::table('redbark_feeds')->whereNotNull('last_synced_at')->get(['id', 'last_synced_at']) as $feed) {
            DB::table('redbark_accounts')
                ->where('redbark_feed_id', $feed->id)
                ->update(['transactions_synced_at' => $feed->last_synced_at]);
        }
    }

    public function down(): void
    {
        Schema::table('redbark_accounts', function (Blueprint $table): void {
            $table->dropColumn('transactions_synced_at');
        });
    }
};
