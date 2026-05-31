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
            $table->boolean('is_pay_cycle_income')
                ->default(false)
                ->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn('is_pay_cycle_income');
        });
    }
};
