<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->date('valued_on');
            $table->bigInteger('balance');
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('opening_marker')->nullable()
                ->virtualAs("case when kind = 'opening_balance' then 1 end");
            $table->timestamps();

            $table->unique(['account_id', 'valued_on']);
            $table->unique(['account_id', 'opening_marker'], 'account_valuations_one_opening_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_valuations');
    }
};
