<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->unique(
                ['account_id', 'csv_hash', 'deleted_at'],
                'transactions_account_csv_hash_deleted_unique',
            );
            $table->dropUnique('transactions_account_csv_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', static function (Blueprint $table): void {
            $table->unique(['account_id', 'csv_hash'], 'transactions_account_csv_hash_unique');
            $table->dropUnique('transactions_account_csv_hash_deleted_unique');
        });
    }
};
