<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Services\UserCategoryProvisioner;
use Illuminate\Database\Seeder;

final class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(UserCategoryProvisioner::class);

        User::query()->eachById(function (User $user) use ($provisioner): void {
            $provisioner->provision($user);
        });
    }
}
