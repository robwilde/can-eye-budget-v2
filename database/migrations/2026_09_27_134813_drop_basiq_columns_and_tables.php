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
        DB::table('transactions')->where('source', 'basiq')->update(['source' => 'csv']);
        DB::table('accounts')->where('import_source', 'basiq')->update(['import_source' => 'manual']);
        // Basiq syncs also stamped balance_source; the Account cast has no Basiq case left.
        DB::table('accounts')->where('balance_source', 'basiq')->update(['balance_source' => 'manual']);

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['basiq_id']);
            $table->dropColumn(['basiq_id', 'basiq_account_id']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['basiq_account_id']);
            $table->dropColumn('basiq_account_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['basiq_user_id']);
            $table->dropColumn(['basiq_user_id', 'last_synced_at']);
        });

        Schema::dropIfExists('basiq_refresh_logs');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('basiq_user_id')->nullable()->unique()->after('email');
            $table->timestamp('last_synced_at')->nullable()->after('basiq_user_id');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('basiq_account_id')->nullable()->unique();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('basiq_id')->nullable()->unique();
            $table->string('basiq_account_id')->nullable();
        });

        Schema::create('basiq_refresh_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('job_ids')->nullable();
            $table->string('trigger');
            $table->string('status');
            $table->unsignedInteger('accounts_synced')->nullable();
            $table->unsignedInteger('transactions_synced')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }
};
