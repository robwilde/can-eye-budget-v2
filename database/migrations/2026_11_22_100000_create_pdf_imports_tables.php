<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PDF import is one AI extraction of a statement PDF held in the vault. Each extracted
 * line is a statement row awaiting review; amounts are signed cents, the same convention as
 * transactions.amount. Lines are scoped to the user through their import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('document_type', 30)->nullable();
            $table->string('date_format', 20)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'pdf_imports_user_status_idx');
            $table->index(['user_id', 'statement_id'], 'pdf_imports_user_statement_idx');
        });

        Schema::create('pdf_import_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pdf_import_id')->constrained()->cascadeOnDelete();
            $table->date('line_date')->nullable();
            $table->string('raw_date', 50);
            $table->boolean('date_unreadable')->default(false);
            $table->bigInteger('amount');
            $table->string('direction', 10);
            $table->string('description', 255);
            $table->string('kind', 20)->default('statement_only');
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['pdf_import_id', 'kind'], 'pdf_import_lines_import_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_import_lines');
        Schema::dropIfExists('pdf_imports');
    }
};
