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
        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->string('transport', 16)->default('rest')->after('user_id');
            $table->text('simplefin_access_url')->nullable()->after('api_key');
            $table->text('api_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('redbark_feeds')->where('transport', 'simplefin')->delete();

        Schema::table('redbark_feeds', function (Blueprint $table): void {
            $table->dropColumn(['transport', 'simplefin_access_url']);
            $table->text('api_key')->nullable(false)->change();
        });
    }
};
