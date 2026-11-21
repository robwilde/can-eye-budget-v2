<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'user_rule_id', 'transaction_id'], 'rule_email_deliveries_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_email_deliveries');
    }
};
