<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_payment_settlements', function (Blueprint $table) {
            $table->foreignId('transaction_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('restored')->default(false);
            $table->boolean('link_changed')->default(false);
            $table->timestamps();

            $table->index('updated_at', 'pending_payment_settlements_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_payment_settlements');
    }
};
