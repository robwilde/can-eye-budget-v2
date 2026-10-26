<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('excluded')->default(false);
            $table->string('idempotency_key', 64)->nullable();

            $table->unique(['user_id', 'idempotency_key'], 'transactions_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_user_idempotency_unique');
            $table->dropColumn(['excluded', 'idempotency_key']);
        });
    }
};
