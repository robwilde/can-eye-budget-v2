<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_emails', function (Blueprint $table) {
            $table->json('details')->nullable()->after('snippet');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_emails', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
