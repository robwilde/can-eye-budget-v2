<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('base_currency', 3)->default('AUD');
            $table->string('timezone', 64)->nullable();
            $table->unsignedTinyInteger('month_start_day')->nullable();
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['base_currency', 'timezone', 'month_start_day', 'preferences']);
        });
    }
};
