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
            // Nullable (not default-false) so the unique index below enforces at
            // most one income row per user: NULLs are distinct, so ordinary
            // planned transactions are unconstrained while only one true value
            // is allowed per user.
            $table->boolean('is_pay_cycle_income')
                ->nullable()
                ->after('is_active');

            $table->unique(['user_id', 'is_pay_cycle_income']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'is_pay_cycle_income']);
            $table->dropColumn('is_pay_cycle_income');
        });
    }
};
