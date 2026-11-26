<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 16);
            $table->string('provider', 60);
            $table->string('model', 120);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'llm_usages_user_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_usages');
    }
};
