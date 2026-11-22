<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kind of file an import was made from (a bank CSV, a Mint, YNAB or Actual Budget export, or a QIF file).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->string('preset', 20)->default('bank_csv');
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->dropColumn('preset');
        });
    }
};
