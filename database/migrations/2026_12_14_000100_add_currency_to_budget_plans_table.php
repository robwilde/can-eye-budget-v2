<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_plans', function (Blueprint $table) {
            $table->char('currency', 3)->default('AUD');
        });

        Schema::getConnection()->table('budget_plans')->update([
            'currency' => Schema::getConnection()->raw('(select base_currency from users where users.id = budget_plans.user_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('budget_plans', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
