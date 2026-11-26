<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScope;
use Carbon\CarbonImmutable;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A personal API key. Sanctum authenticates it by the SHA-256 hash of the secret and compares it
 * in constant time; the plain token (prefix, secret, checksum) exists only in the value returned
 * when the key is issued. A revoked key never authenticates.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token
 * @property ApiKeyScope $scope
 * @property list<string> $abilities
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ApiKey extends PersonalAccessToken
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    public const int MAX_ACTIVE_KEYS = 20;

    protected $table = 'api_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'scope',
        'abilities',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Creates a key for the user and returns it with the plain token, which is not recoverable.
     *
     * @return array{key: self, token: string}
     */
    public static function issue(User $user, string $name, ApiKeyScope $scope, ?CarbonImmutable $expiresAt): array
    {
        $secret = Str::random(40);
        $plain = config('sanctum.token_prefix', '').$secret.hash('crc32b', $secret);

        $key = new self([
            'name' => $name,
            'scope' => $scope,
            'abilities' => $scope->abilities(),
            'expires_at' => $expiresAt,
        ]);
        $key->forceFill([
            'user_id' => $user->id,
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'token' => hash('sha256', $plain),
        ])->save();

        return ['key' => $key, 'token' => $key->id.'|'.$plain];
    }

    public static function findToken($token): ?static
    {
        $found = parent::findToken($token);

        return $found instanceof self && $found->revoked_at === null ? $found : null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(
            fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()),
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => ApiKeyScope::class,
            'abilities' => 'json',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
