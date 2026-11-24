<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_alert_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('dedupe_key', 80);
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'dedupe_key'], 'bill_alert_sends_user_kind_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_alert_sends');
    }
};
