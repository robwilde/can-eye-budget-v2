<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('direction');
            $table->string('description');
            $table->string('clean_description')->nullable();
            $table->date('post_date');
            $table->date('transaction_date')->nullable();
            $table->string('status')->default('posted');
            $table->string('basiq_id')->nullable()->unique();
            $table->string('basiq_account_id')->nullable();
            $table->string('merchant_name')->nullable();
            $table->string('anzsic_code')->nullable();
            $table->json('enrich_data')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'post_date']);
            $table->index('anzsic_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
