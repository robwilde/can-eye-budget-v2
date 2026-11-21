<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redbark_accounts', function (Blueprint $table) {
            $table->boolean('category_matcher_enabled')->default(true)->after('ignored');
        });

        Schema::table('bank_imports', function (Blueprint $table) {
            $table->json('unresolved_categories')->nullable()->after('row_errors');
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', function (Blueprint $table) {
            $table->dropColumn('unresolved_categories');
        });

        Schema::table('redbark_accounts', function (Blueprint $table) {
            $table->dropColumn('category_matcher_enabled');
        });
    }
};
