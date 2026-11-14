<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('priority');
            $table->string('status')->default('active');
            $table->string('dedup_key');
            $table->string('title');
            $table->text('body');
            $table->json('figures');
            $table->json('comparison');
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action_route')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedup_key']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights');
    }
};
