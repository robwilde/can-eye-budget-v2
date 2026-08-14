<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            // The dedup key for the Redbark feed, mirroring basiq_id.
            $table->string('redbark_id')->nullable()->unique()->after('basiq_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['redbark_id']);
            $table->dropColumn('redbark_id');
        });
    }
};
