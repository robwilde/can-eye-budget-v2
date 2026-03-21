<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('pay_amount')->nullable();
            $table->string('pay_frequency')->nullable();
            $table->date('next_pay_date')->nullable();
            $table->bigInteger('committed_per_cycle')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pay_amount', 'pay_frequency', 'next_pay_date', 'committed_per_cycle']);
        });
    }
};
