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
            $table->foreignId('parent_transaction_id')
                ->nullable()
                ->after('planned_transaction_id')
                ->constrained('transactions')
                ->nullOnDelete();

            $table->softDeletes();

            $table->index(['parent_transaction_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['parent_transaction_id', 'deleted_at']);
            $table->dropConstrainedForeignId('parent_transaction_id');
            $table->dropSoftDeletes();
        });
    }
};
