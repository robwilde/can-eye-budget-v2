<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurrence_interval')->default(1)->after('frequency');
            $table->json('days_of_month')->nullable()->after('recurrence_interval');
            $table->string('weekend_adjustment')->default('none')->after('days_of_month');
            $table->boolean('schedule_pinned')->default(false)->after('weekend_adjustment');
        });
    }

    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn(['recurrence_interval', 'days_of_month', 'weekend_adjustment', 'schedule_pinned']);
        });
    }
};
