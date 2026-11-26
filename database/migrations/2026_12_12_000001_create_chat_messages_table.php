<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->string('status', 16);
            $table->string('error_kind', 24)->nullable();
            $table->string('model', 120)->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'id'], 'chat_messages_chat_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
