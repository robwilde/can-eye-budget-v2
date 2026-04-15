<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->whereNotNull('basiq_id')
            ->where('source', 'manual')
            ->update(['source' => 'basiq']);
    }

    public function down(): void
    {
        // No-op: cannot reliably distinguish original manual rows from fixed ones.
    }
};
