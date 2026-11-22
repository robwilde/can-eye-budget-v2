<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->foreignId('original_security_id')->nullable()->after('security_id')->constrained('securities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_security_id');
        });
    }
};
