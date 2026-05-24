<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_imports', static function (Blueprint $table): void {
            $table->unsignedInteger('restored_count')->default(0)->after('skipped_count');
            $table->json('row_errors')->nullable()->after('error_summary');
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', static function (Blueprint $table): void {
            $table->dropColumn(['restored_count', 'row_errors']);
        });
    }
};
