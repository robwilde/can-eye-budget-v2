<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user may hold one Redbark feed per transport instead of one feed in total. The new unique
 * index starts with user_id, so it also serves the foreign key while the old one is dropped.
 * An app account still links to at most one Redbark account: that unique index stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->unique(['user_id', 'transport'], 'redbark_feeds_user_transport_unique');
        });

        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->dropUnique('redbark_feeds_user_id_unique');
        });
    }

    public function down(): void
    {
        $keep = DB::table('redbark_feeds')->selectRaw('min(id) as id')->groupBy('user_id');

        DB::table('redbark_feeds')->whereNotIn('id', $keep->pluck('id'))->delete();

        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->unique('user_id', 'redbark_feeds_user_id_unique');
        });

        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->dropUnique('redbark_feeds_user_transport_unique');
        });
    }
};
