<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\MonthEndBalanceRuleProvisioner;
use App\Services\UserCategoryProvisioner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return Cache::lock('register-first-user', 10)->block(5, fn (): User => DB::transaction(function () use ($input): User {
            $isFirstUser = ! User::query()->exists();

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($isFirstUser) {
                $user->forceFill(['is_admin' => true])->save();
            }

            app(UserCategoryProvisioner::class)->provision($user);
            app(MonthEndBalanceRuleProvisioner::class)->provisionForUser($user->id);

            return $user;
        }));
    }
}
