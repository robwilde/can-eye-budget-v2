<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_daily_balances', function (Blueprint $table) {
            $table->bigInteger('start_non_cash_balance')->nullable()->after('end_balance');
            $table->bigInteger('end_non_cash_balance')->nullable()->after('start_non_cash_balance');
            $table->bigInteger('market_movement')->nullable()->after('outflows');
        });
    }

    public function down(): void
    {
        Schema::table('account_daily_balances', function (Blueprint $table) {
            $table->dropColumn(['start_non_cash_balance', 'end_non_cash_balance', 'market_movement']);
        });
    }
};
