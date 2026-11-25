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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarded_at')->nullable();
        });

        DB::table('users')
            ->whereNotNull('pay_amount')
            ->whereNotNull('pay_frequency')
            ->whereNotNull('next_pay_date')
            ->whereExists(fn ($query) => $query
                ->select(DB::raw(1))
                ->from('redbark_feeds')
                ->whereColumn('redbark_feeds.user_id', 'users.id')
                ->whereNotNull('redbark_feeds.last_synced_at'))
            ->update(['onboarded_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarded_at');
        });
    }
};
