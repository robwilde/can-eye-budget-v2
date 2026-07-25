<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bnpl_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bnpl_order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->json('payload')->nullable();
            $table->string('actor')->nullable();
            $table->timestamps();

            $table->index(['bnpl_order_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bnpl_order_events');
    }
};
