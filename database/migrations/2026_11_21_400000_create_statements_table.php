<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The statement vault: one row per uploaded statement file, owned by the user, optionally
 * linked to an account and separately suggested for one. Balances are signed cents.
 * A statement reconciliation can point at the statement it was checked from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('suggested_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('suggestion_confidence', 5, 4)->nullable();
            $table->string('review_status', 20)->default('unmatched');
            $table->char('content_hash', 64);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('byte_size');
            $table->string('file_type', 10);
            $table->string('institution_hint')->nullable();
            $table->string('account_name_hint')->nullable();
            $table->string('last_four_hint', 4)->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->bigInteger('opening_balance')->nullable();
            $table->bigInteger('closing_balance')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'content_hash'], 'statements_user_content_hash_unique');
            $table->index(['user_id', 'review_status'], 'statements_user_review_status_idx');
        });

        Schema::table('statement_reconciliations', function (Blueprint $table) {
            $table->foreignId('statement_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('statement_reconciliations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('statement_id');
        });

        Schema::dropIfExists('statements');
    }
};
