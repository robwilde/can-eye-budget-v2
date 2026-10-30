<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->char('currency', 3)->default('AUD');
            $table->decimal('exchange_rate', 18, 8)->nullable();
        });

        Schema::getConnection()->table('transactions')->update([
            'currency' => Schema::getConnection()->raw('(select currency from accounts where accounts.id = transactions.account_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
