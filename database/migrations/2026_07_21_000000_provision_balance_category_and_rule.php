<?php

declare(strict_types=1);

use App\Models\Category;
use App\Services\MonthEndBalanceRuleProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Category::query()->doesntExist()) {
            return;
        }

        Category::query()->firstOrCreate(
            [
                'name' => MonthEndBalanceRuleProvisioner::CATEGORY_NAME,
                'parent_id' => null,
            ],
            [
                'icon' => 'building-library',
                'is_hidden' => false,
            ],
        );

        app(MonthEndBalanceRuleProvisioner::class)->provisionAllUsers();
    }

    public function down(): void {}
};
