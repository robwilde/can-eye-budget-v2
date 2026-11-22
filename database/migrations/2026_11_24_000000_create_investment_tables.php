<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('securities', function (Blueprint $table): void {
            $table->id();
            $table->string('ticker', 32);
            $table->string('exchange_mic', 16)->default('');
            $table->string('name');
            $table->char('currency', 3);
            $table->string('provider', 32)->nullable();
            $table->string('offline_reason')->nullable();
            $table->timestamp('offline_at')->nullable();
            $table->timestamps();

            $table->unique(['ticker', 'exchange_mic']);
        });

        Schema::create('security_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->bigInteger('price');
            $table->char('currency', 3);
            $table->timestamps();

            $table->unique(['security_id', 'date']);
        });

        Schema::create('security_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('ratio', 24, 8);
            $table->timestamps();

            $table->unique(['security_id', 'date']);
        });

        Schema::create('holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('security_id')->constrained()->restrictOnDelete();
            $table->foreignId('original_security_id')->nullable()->constrained('securities')->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 24, 8);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('cost_basis_per_share')->nullable();
            $table->string('cost_basis_source', 16)->nullable();
            $table->boolean('cost_basis_locked')->default(false);
            $table->boolean('provider_locked')->default(false);
            $table->timestamps();

            $table->unique(['account_id', 'security_id', 'date', 'currency'], 'holdings_account_security_date_currency_unique');
        });

        Schema::create('trades', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('security_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('quantity', 24, 8);
            $table->bigInteger('price');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('activity', 24);
            $table->string('name')->nullable();
            $table->boolean('exclude_from_analytics')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'date']);
            $table->index(['security_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
        Schema::dropIfExists('holdings');
        Schema::dropIfExists('security_splits');
        Schema::dropIfExists('security_prices');
        Schema::dropIfExists('securities');
    }
};
