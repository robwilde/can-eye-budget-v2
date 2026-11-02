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
            $table->foreignId('transfer_fee_for_id')
                ->nullable()
                ->after('suggested_pair_id')
                ->constrained('transactions', indexName: 'transactions_transfer_fee_for_id_foreign')
                ->cascadeOnDelete();
            $table->string('idempotency_fingerprint', 64)->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transfer_fee_for_id');
            $table->dropColumn('idempotency_fingerprint');
        });
    }
};
