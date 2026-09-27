<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A statement reconciliation checks one account's bank statement CSV for one calendar
 * month against the transactions the feed delivered. Each line is a statement row
 * (matched or statement-only) or a feed row the statement does not show (feed-only).
 * Amounts are signed cents, the same convention as transactions.amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('open');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->json('column_mapping');
            $table->bigInteger('statement_debit_total')->default(0);
            $table->bigInteger('statement_credit_total')->default(0);
            $table->bigInteger('closing_balance')->nullable();
            $table->unsignedInteger('lines_outside_period')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'period_start']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('statement_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_reconciliation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->char('csv_hash', 64)->nullable();
            $table->date('post_date');
            $table->bigInteger('amount');
            $table->string('description', 255);
            $table->string('resolution', 20)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['statement_reconciliation_id', 'kind'], 'statement_lines_reconciliation_kind_index');
            $table->index(['statement_reconciliation_id', 'csv_hash'], 'statement_lines_reconciliation_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_reconciliation_lines');
        Schema::dropIfExists('statement_reconciliations');
    }
};
