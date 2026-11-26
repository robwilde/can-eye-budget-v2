<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssistantErrorKind;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The text of a chat is private to its owner and is never logged or sent to error reporting.
 *
 * @property int $id
 * @property int $chat_id
 * @property ChatMessageRole $role
 * @property string $content
 * @property ChatMessageStatus $status
 * @property AssistantErrorKind|null $error_kind
 * @property string|null $model
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'chat_id',
        'role',
        'content',
        'status',
        'error_kind',
        'model',
    ];

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'status' => ChatMessageStatus::class,
            'error_kind' => AssistantErrorKind::class,
        ];
    }
}
