<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sync log records when the user cancelled the run and how far back it must read, so a request
 * that arrives while it is open can widen it. A linked Redbark account records when the provider
 * stopped reporting it. Both tables are scoped to the user through their feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redbark_sync_logs', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->date('since_date')->nullable();
        });

        Schema::table('redbark_accounts', function (Blueprint $table): void {
            $table->timestamp('missing_upstream_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('redbark_accounts', function (Blueprint $table): void {
            $table->dropColumn('missing_upstream_at');
        });

        Schema::table('redbark_sync_logs', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'since_date']);
        });
    }
};
