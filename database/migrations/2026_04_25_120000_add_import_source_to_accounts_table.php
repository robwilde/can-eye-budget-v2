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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('import_source')->default('manual')->after('basiq_account_id');
            $table->string('account_last4', 4)->nullable()->after('name');
            $table->json('column_mapping')->nullable()->after('description');
        });

        DB::table('accounts')
            ->whereNotNull('basiq_account_id')
            ->update(['import_source' => 'basiq']);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['import_source', 'account_last4', 'column_mapping']);
        });
    }
};
