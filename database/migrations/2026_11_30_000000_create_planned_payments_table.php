<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->date('paid_on');
            $table->timestamps();

            $table->unique(['planned_occurrence_id', 'transaction_id'], 'planned_payments_occurrence_transaction_unique');
            $table->index('transaction_id');
        });

        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->json('learned_aliases')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('planned_transactions', function (Blueprint $table) {
            $table->dropColumn('learned_aliases');
        });

        Schema::dropIfExists('planned_payments');
    }
};
