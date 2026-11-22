<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The parsed rows of a CSV import, kept so the user can fix invalid cells before importing.
 * Rows are scoped to the user through their import. value_mapping holds the user's choices
 * for each distinct category and tag value in the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('date')->nullable();
            $table->string('raw_date', 50)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->string('raw_amount', 50)->nullable();
            $table->string('direction', 10)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('category_value', 255)->nullable();
            $table->string('tag_value', 255)->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['bank_import_id', 'row_number'], 'bank_import_rows_import_row_unique');
        });

        Schema::table('bank_imports', function (Blueprint $table) {
            $table->json('value_mapping')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->dropColumn('value_mapping');
        });

        Schema::dropIfExists('bank_import_rows');
    }
};
