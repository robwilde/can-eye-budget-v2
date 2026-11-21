<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bank_import_id is set only when a CSV import creates the row, and
 * restored_by_bank_import_id when it restores a soft-deleted one, so an import can be
 * reverted without touching rows an earlier import or the user made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('bank_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restored_by_bank_import_id')->nullable()->constrained('bank_imports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('restored_by_bank_import_id');
            $table->dropConstrainedForeignId('bank_import_id');
        });
    }
};
