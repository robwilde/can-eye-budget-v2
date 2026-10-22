<?php

declare(strict_types=1);

namespace App\Casts;

use App\DTOs\UserPreferences;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<UserPreferences, UserPreferences|array<string, mixed>|null> */
final class UserPreferencesCast implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): UserPreferences
    {
        $stored = is_string($value) ? json_decode($value, true) : null;

        return UserPreferences::fromStored(is_array($stored) ? $stored : []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $preferences = $value instanceof UserPreferences ? $value : UserPreferences::from($value);

        return json_encode($preferences->toArray(), JSON_THROW_ON_ERROR);
    }
}
