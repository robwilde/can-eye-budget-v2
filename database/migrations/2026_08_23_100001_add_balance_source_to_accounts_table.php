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
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('balance_source')->nullable()->after('balance');
            $table->timestamp('balance_updated_at')->nullable()->after('balance_source');
        });

        // Best available truth for rows that predate the column: whatever last owned the account.
        DB::table('accounts')->update(['balance_source' => DB::raw('import_source')]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['balance_source', 'balance_updated_at']);
        });
    }
};
