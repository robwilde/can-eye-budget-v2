<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_rules', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('user_rules', function (Blueprint $table) {
            $table->dropColumn('effective_from');
        });
    }
};
