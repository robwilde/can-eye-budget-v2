<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's uploaded document, kept for later indexing and search.
 *
 * @property int $id
 * @property int $user_id
 * @property string $filename
 * @property string $mime_type
 * @property int $byte_size
 * @property string $stored_path
 * @property string $content_hash
 * @property DocumentStatus $status
 * @property string|null $vector_file_id
 * @property string|null $error_message
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'filename',
        'mime_type',
        'byte_size',
        'stored_path',
        'content_hash',
        'status',
        'vector_file_id',
        'error_message',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'byte_size' => 'integer',
        ];
    }
}
