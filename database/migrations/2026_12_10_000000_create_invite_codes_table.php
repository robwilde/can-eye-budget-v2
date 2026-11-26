<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('code_hint', 8);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_codes');
    }
};
