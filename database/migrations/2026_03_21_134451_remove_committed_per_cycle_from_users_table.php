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
            $table->dropColumn('committed_per_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('committed_per_cycle')->nullable();
        });
    }
};
