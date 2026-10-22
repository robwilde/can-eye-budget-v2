<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A user-owned free-form label that can sit on many transactions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $colour
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public const string RESERVED_NAME = '__untagged__';

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
        'colour',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Transaction, $this> */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class);
    }

    protected static function booted(): void
    {
        self::saving(static function (self $tag): void {
            $tag->colour ??= Arr::random(self::PALETTE);

            Validator::make($tag->only(['name', 'colour']), [
                'name' => ['required', 'string', 'max:50', Rule::notIn([self::RESERVED_NAME])],
                'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}\z/'],
            ])->validate();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
