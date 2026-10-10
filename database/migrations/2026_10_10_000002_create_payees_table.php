<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (user, merchant_key): Jev's suggestion for the payee and the
 * user's answer to it. A confirmed row is the labelled example behind the
 * "usual category for similar payees" hint, and its rule keeps later
 * transactions away from Jev.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_key');
            $table->string('merchant_name');
            $table->string('status')->default('pending');
            $table->foreignId('suggested_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->decimal('top_to_second', 10, 2)->nullable();
            $table->json('probabilities')->nullable();
            $table->boolean('auto_applied')->default(false);
            $table->foreignId('confirmed_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('budget_tag')->nullable();
            $table->foreignId('user_rule_id')->nullable()->constrained('user_rules')->nullOnDelete();
            $table->timestamp('suggested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'merchant_key']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payees');
    }
};
