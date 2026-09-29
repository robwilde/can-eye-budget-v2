<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('counterpart_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('description_pattern');
            $table->timestamps();

            $table->unique(['user_id', 'account_id', 'counterpart_account_id', 'description_pattern'], 'transfer_rules_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_rules');
    }
};
