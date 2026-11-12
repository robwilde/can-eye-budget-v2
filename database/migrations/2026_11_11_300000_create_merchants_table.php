<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('merchant_key');
            $table->string('colour', 7);
            $table->string('website')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->unique(['user_id', 'merchant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
