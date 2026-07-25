<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bnpl_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('retailer');
            $table->string('order_ref', 64);
            $table->bigInteger('total');
            $table->bigInteger('instalment_amount');
            $table->unsignedTinyInteger('instalment_count');
            $table->date('first_due_date');
            $table->date('last_due_date');
            $table->string('frequency', 32)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('card_last4', 4)->nullable();
            $table->string('status', 20)->default('pending_review');
            $table->string('review_note')->nullable();
            $table->foreignId('planned_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gmail_message_id');
            $table->string('subject');
            $table->timestamp('email_date')->nullable();
            $table->text('snippet')->nullable();
            $table->string('gmail_url', 2048);
            $table->json('parsed_payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'gmail_message_id']);
            $table->unique(['user_id', 'provider', 'order_ref']);
            $table->index(['user_id', 'provider', 'retailer']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bnpl_orders');
    }
};
