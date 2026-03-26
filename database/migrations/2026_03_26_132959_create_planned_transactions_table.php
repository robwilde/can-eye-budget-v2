<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('direction');
            $table->string('description');
            $table->date('start_date');
            $table->string('frequency');
            $table->date('until_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('last_generated_date')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_date']);
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_transactions');
    }
};
