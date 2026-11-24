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
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('is_active');
        });

        DB::table('planned_transactions')->where('is_active', false)->update(['status' => 'inactive']);

        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_active']);
        });

        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn('is_active');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->boolean('is_active')->default(true)->after('status');
        });

        DB::table('planned_transactions')->where('status', '!=', 'active')->update(['is_active' => false]);

        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn('status');
            $table->index(['user_id', 'is_active']);
        });
    }
};
