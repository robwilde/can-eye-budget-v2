<?php

declare(strict_types=1);

use App\Services\MonthEndBalanceRuleProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(MonthEndBalanceRuleProvisioner::class)->provisionAllUsers();
    }

    public function down(): void {}
};
