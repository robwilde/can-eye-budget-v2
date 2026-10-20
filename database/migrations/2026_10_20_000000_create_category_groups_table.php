<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 7);
            $table->string('icon');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('group_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('category_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('group_id');
        });

        Schema::dropIfExists('category_groups');
    }
};
