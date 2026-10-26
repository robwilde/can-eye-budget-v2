<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'rate_date'], 'exchange_rates_pair_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
