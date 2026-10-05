<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('clean_description_source')->nullable()->after('clean_description');
        });

        DB::table('transactions')
            ->whereNotNull('clean_description')
            ->where('clean_description', '!=', '')
            ->update(['clean_description_source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('clean_description_source');
        });
    }
};
