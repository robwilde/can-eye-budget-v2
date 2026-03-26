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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('enrich_data');
            $table->foreignId('transfer_pair_id')->nullable()->after('source')
                ->constrained('transactions')->nullOnDelete();
            $table->unsignedBigInteger('planned_transaction_id')->nullable()->after('transfer_pair_id');
            $table->text('notes')->nullable()->after('planned_transaction_id');
        });

        DB::table('transactions')
            ->whereNotNull('basiq_id')
            ->update(['source' => 'basiq']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['transfer_pair_id']);
            $table->dropColumn(['source', 'transfer_pair_id', 'planned_transaction_id', 'notes']);
        });
    }
};
