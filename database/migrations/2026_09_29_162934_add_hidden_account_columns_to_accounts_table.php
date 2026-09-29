<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('is_tracked')->default(true)->after('status');
            $table->date('reconciled_on')->nullable()->after('is_tracked');
            $table->bigInteger('reconcile_difference')->nullable()->after('reconciled_on');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['is_tracked', 'reconciled_on', 'reconcile_difference']);
        });
    }
};
