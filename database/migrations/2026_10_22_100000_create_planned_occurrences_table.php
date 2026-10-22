<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_transaction_id')->constrained()->cascadeOnDelete();
            $table->date('original_date');
            $table->date('due_date');
            $table->date('snoozed_until')->nullable();
            $table->string('status')->default('scheduled');
            $table->bigInteger('expected_amount')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('closed_manually')->default(false);
            $table->timestamps();

            $table->unique(['planned_transaction_id', 'original_date']);
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_occurrences');
    }
};
