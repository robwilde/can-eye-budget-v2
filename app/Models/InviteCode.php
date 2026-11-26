<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InviteCodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use sign-up code. Only a SHA-256 hash of the code is stored (the code has 190 bits of
 * entropy, so an unsalted hash is enough), with the last characters kept as a hint for the admin list.
 * The plain code exists only in the value returned when it is issued.
 *
 * @property int $id
 * @property string $code_hash
 * @property string $code_hint
 * @property int|null $created_by_user_id
 * @property int|null $claimed_by_user_id
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class InviteCode extends Model
{
    /** @use HasFactory<InviteCodeFactory> */
    use HasFactory;

    public const int DEFAULT_VALID_DAYS = 7;

    public const int MAX_VALID_DAYS = 90;

    private const int CODE_LENGTH = 32;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code_hash',
        'code_hint',
        'created_by_user_id',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'code_hash',
    ];

    public static function hashOf(string $code): string
    {
        return hash('sha256', mb_trim($code));
    }

    /**
     * Creates a code that expires after the given number of days and returns it with the plain
     * value, which is not recoverable afterwards.
     *
     * @return array{invite: self, code: string}
     */
    public static function issue(User $creator, int $validDays = self::DEFAULT_VALID_DAYS): array
    {
        $code = Str::random(self::CODE_LENGTH);

        $invite = self::query()->create([
            'code_hash' => self::hashOf($code),
            'code_hint' => mb_substr($code, -4),
            'created_by_user_id' => $creator->id,
            'expires_at' => CarbonImmutable::now()->addDays(max(1, min($validDays, self::MAX_VALID_DAYS))),
        ]);

        return ['invite' => $invite, 'code' => $code];
    }

    /**
     * Marks the code claimed for the user with one conditional update, so two sign-ups racing for
     * the same code cannot both win. A missing, claimed, revoked or expired code claims nothing.
     * The stored hash is compared again in constant time before the claim is trusted.
     */
    public static function claim(string $code, User $user): bool
    {
        $hash = self::hashOf($code);
        $found = self::query()->where('code_hash', $hash)->first();

        if ($found === null || ! hash_equals($found->code_hash, $hash)) {
            return false;
        }

        return self::query()
            ->whereKey($found->id)
            ->usable()
            ->update(['claimed_at' => CarbonImmutable::now(), 'claimed_by_user_id' => $user->id]) === 1;
    }

    public static function usableCodeExists(string $code): bool
    {
        $hash = self::hashOf($code);
        $found = self::query()->where('code_hash', $hash)->usable()->first();

        return $found !== null && hash_equals($found->code_hash, $hash);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('claimed_at')->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now());
    }

    public function status(): string
    {
        return match (true) {
            $this->claimed_at !== null => 'claimed',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'unused',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
