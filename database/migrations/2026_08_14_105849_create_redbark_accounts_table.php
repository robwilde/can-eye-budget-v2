<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redbark_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('redbark_feed_id')->constrained()->cascadeOnDelete();
            // Null means unlinked, so the account still shows in the setup wizard.
            // Unique means an app account can carry at most one Redbark feed.
            $table->foreignId('account_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('redbark_account_id');
            $table->string('bank_connection_id')->nullable();
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->char('currency', 3)->default('AUD');
            $table->bigInteger('current_balance')->nullable();
            $table->string('account_type')->nullable();
            $table->string('institution_name')->nullable();
            $table->boolean('ignored')->default(false);
            $table->date('sync_start_date')->nullable();
            // Load-bearing, not a debug artefact: a non-empty snapshot selects the 7-day
            // incremental window over the 90-day backfill, and its diff against a refetch
            // is how a settled pending row is detected.
            $table->json('raw_transactions_payload')->nullable();
            $table->timestamps();

            $table->unique(['redbark_feed_id', 'redbark_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redbark_accounts');
    }
};
