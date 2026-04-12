<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained()->cascadeOnDelete();
            $table->string('stage');
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['pipeline_run_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_audit_entries');
    }
};
