<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('rate_type')->nullable();
            $table->decimal('rate', 6, 3)->nullable();
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->date('start_date')->nullable();
            $table->bigInteger('original_principal')->nullable();
            $table->bigInteger('down_payment')->nullable();
            $table->bigInteger('insurance')->nullable();
            $table->bigInteger('minimum_payment')->nullable();
            $table->bigInteger('annual_fee')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_details');
    }
};
