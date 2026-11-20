<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind')->default('one_off');
            $table->string('status')->default('active');
            $table->bigInteger('target_amount');
            $table->date('target_date')->nullable();
            $table->unsignedSmallInteger('target_months_of_expenses')->nullable();
            $table->string('colour')->nullable();
            $table->string('icon')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('frozen_saved_amount')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('goal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('earmark_amount')->nullable();
            $table->timestamps();

            $table->unique(['goal_id', 'account_id']);
        });

        Schema::create('goal_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->string('status')->default('open');
            $table->timestamp('expires_at');
            $table->foreignId('matched_transaction_id')->nullable()->unique()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_pledges');
        Schema::dropIfExists('goal_accounts');
        Schema::dropIfExists('goals');
    }
};
