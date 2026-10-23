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
        Schema::table('budgets', function (Blueprint $table) {
            $table->bigInteger('limit_amount')->nullable()->change();
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignId('budget_plan_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('rollover_enabled')->default(false);
            $table->unsignedBigInteger('rolled_over_amount')->default(0);

            $table->unique(['budget_plan_id', 'category_id']);
        });
    }

    public function down(): void
    {
        DB::table('budgets')->whereNotNull('budget_plan_id')->delete();

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['budget_plan_id']);
            $table->dropUnique(['budget_plan_id', 'category_id']);
            $table->dropColumn(['budget_plan_id', 'rollover_enabled', 'rolled_over_amount']);
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->bigInteger('limit_amount')->nullable(false)->change();
        });
    }
};
