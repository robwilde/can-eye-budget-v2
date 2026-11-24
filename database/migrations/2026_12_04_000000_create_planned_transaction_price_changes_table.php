<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_transaction_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_transaction_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('previous_amount');
            $table->bigInteger('new_amount');
            $table->date('effective_on');
            $table->timestamps();

            $table->unique(['planned_transaction_id', 'effective_on'], 'plan_price_changes_plan_effective_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_transaction_price_changes');
    }
};
