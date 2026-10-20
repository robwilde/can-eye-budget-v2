<?php

declare(strict_types=1);

use App\Models\Category;
use App\Services\CategoryGroupProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Category::query()->doesntExist()) {
            return;
        }

        app(CategoryGroupProvisioner::class)->provision();
    }

    public function down(): void {}
};
