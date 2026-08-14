<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redbark_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('redbark_feed_id')->constrained()->cascadeOnDelete();
            $table->string('trigger');
            $table->string('status');
            $table->unsignedInteger('accounts_synced')->nullable();
            $table->unsignedInteger('transactions_created')->nullable();
            $table->unsignedInteger('transactions_updated')->nullable();
            $table->unsignedInteger('balances_updated')->nullable();
            // List of {context: string, message: string}. This table is the user-facing
            // debug surface for the feed.
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redbark_sync_logs');
    }
};
