<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Recurring\MerchantSignature;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A user-owned merchant the user can edit, linked from transactions and
 * reached from other spellings through MerchantAlias.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $merchant_key
 * @property string $colour
 * @property string|null $website
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /** @var list<string> */
    public const array PALETTE = [
        '#EF4444', '#F97316', '#EAB308', '#22C55E', '#14B8A6',
        '#06B6D4', '#3B82F6', '#6366F1', '#A855F7', '#EC4899',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'merchant_key',
        'colour',
        'website',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<MerchantAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(MerchantAlias::class);
    }

    protected static function booted(): void
    {
        self::saving(static function (self $merchant): void {
            Validator::make($merchant->only(['name', 'website']), [
                'name' => [
                    'required', 'string', 'max:100',
                    Rule::unique('merchants', 'name')
                        ->where('user_id', $merchant->user_id)
                        ->ignore($merchant->id),
                ],
                'website' => ['nullable', 'string', 'max:255'],
            ])->validate();

            if (blank($merchant->colour) || preg_match('/^#[0-9A-Fa-f]{6}\z/', $merchant->colour) !== 1) {
                $merchant->colour = Arr::random(self::PALETTE);
            }

            if (blank($merchant->merchant_key)) {
                $merchant->merchant_key = MerchantSignature::for($merchant->name);
            }

            Validator::make(['name' => $merchant->name], [
                'name' => [static function (string $attribute, mixed $value, Closure $fail) use ($merchant): void {
                    $conflict = self::query()
                        ->where('user_id', $merchant->user_id)
                        ->where('merchant_key', $merchant->merchant_key)
                        ->when($merchant->exists, fn (Builder $query): Builder => $query->whereKeyNot($merchant->id))
                        ->value('name');

                    if (is_string($conflict)) {
                        $fail("This name matches the existing merchant \"{$conflict}\".");
                    }
                }],
            ])->validate();
        });
    }
}
