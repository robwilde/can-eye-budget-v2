<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_match_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'planned_transaction_id', 'transaction_id'], 'planned_match_rejections_pair_unique');
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_match_rejections');
    }
};
