<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pdf_import_id marks rows a PDF import created, so reverting removes exactly those.
 * reconciled_statement_id marks a transaction a vault statement confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('pdf_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reconciled_statement_id')->nullable()->constrained('statements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciled_statement_id');
            $table->dropConstrainedForeignId('pdf_import_id');
        });
    }
};
