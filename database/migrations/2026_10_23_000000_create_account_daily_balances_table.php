<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_daily_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('balance_date');
            $table->bigInteger('start_balance');
            $table->bigInteger('end_balance');
            $table->bigInteger('inflows')->default(0);
            $table->bigInteger('outflows')->default(0);
            $table->bigInteger('adjustments')->default(0);
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['account_id', 'balance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_daily_balances');
    }
};
