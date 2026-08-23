<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->unsignedSmallInteger('auth_failure_count')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->dropColumn('auth_failure_count');
        });
    }
};
