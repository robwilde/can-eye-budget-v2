<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->foreignId('transfer_to_account_id')
                ->nullable()
                ->after('account_id')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_to_account_id');
        });
    }
};
