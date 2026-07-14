<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('gmail_message_id');
            $table->string('subject');
            $table->string('from_name')->nullable();
            $table->string('from_address');
            $table->timestamp('email_date')->nullable();
            $table->text('snippet')->nullable();
            $table->string('gmail_url', 2048);
            $table->timestamps();

            $table->unique(['transaction_id', 'gmail_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_emails');
    }
};
