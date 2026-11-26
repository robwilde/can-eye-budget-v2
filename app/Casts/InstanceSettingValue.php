<?php

declare(strict_types=1);

namespace App\Casts;

use App\Models\InstanceSetting;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use LogicException;

/**
 * An instance setting's value: plain text, or encrypted with the app key when the setting is a secret. The key
 * attribute must be set before the value so the cast knows which kind it is holding.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class InstanceSettingValue implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! $this->isSecret($attributes)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->isSecret($attributes) ? Crypt::encryptString((string) $value) : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function isSecret(array $attributes): bool
    {
        $name = $attributes['key'] ?? null;

        if (! is_string($name)) {
            throw new LogicException('Set the instance setting key before its value.');
        }

        return in_array($name, InstanceSetting::SECRET_KEYS, true);
    }
}
