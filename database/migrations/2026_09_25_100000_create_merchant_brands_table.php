<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidecar for Context.dev merchant brands, keyed by (user_id, merchant_key).
 *
 * Brand data deliberately does not live on transactions: merchant_name is the
 * first source of merchant_key, which drives category rules, the rule backlog
 * and recurring clustering, so writing a brand there would re-key rows. One row
 * per merchant per user also means one paid lookup per merchant, not per row.
 *
 * Per-user rather than global because descriptors can carry payee names.
 * retry_after is when the row may be looked up again; vetoed rows never are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_key');
            $table->string('status');
            $table->string('title')->nullable();
            $table->string('domain')->nullable();
            $table->text('logo_url')->nullable();
            $table->string('industry')->nullable();
            $table->string('subindustry')->nullable();
            $table->boolean('partial')->default(false);
            $table->string('source_descriptor', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'merchant_key'], 'merchant_brands_user_merchant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_brands');
    }
};
