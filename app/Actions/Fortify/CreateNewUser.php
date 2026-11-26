<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\RegistrationMode;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\MonthEndBalanceRuleProvisioner;
use App\Services\UserCategoryProvisioner;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    private const string INVALID_INVITE = 'This invite code is not valid.';

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $mode = RegistrationMode::current();

        if (! $mode->allowsRegistration()) {
            throw ValidationException::withMessages(['email' => __('Registration is closed.')]);
        }

        $inviteCode = $mode->requiresInvite() && is_string($input['invite_code'] ?? null) ? mb_trim($input['invite_code']) : null;

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            ...($mode->requiresInvite() ? ['invite_code' => ['required', 'string', 'max:128', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! InviteCode::usableCodeExists($value)) {
                    $fail(self::INVALID_INVITE);
                }
            }]] : []),
        ])->validate();

        return Cache::lock('register-first-user', 10)->block(5, fn (): User => DB::transaction(function () use ($input, $inviteCode): User {
            $isFirstUser = ! User::query()->exists();

            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($inviteCode !== null && ! InviteCode::claim($inviteCode, $user)) {
                throw ValidationException::withMessages(['invite_code' => self::INVALID_INVITE]);
            }

            if ($isFirstUser) {
                $user->forceFill(['is_admin' => true])->save();
            }

            app(UserCategoryProvisioner::class)->provision($user);
            app(MonthEndBalanceRuleProvisioner::class)->provisionForUser($user->id);

            return $user;
        }));
    }
}
