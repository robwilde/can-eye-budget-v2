<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LlmOperation;
use Carbon\CarbonImmutable;
use Database\Factories\LlmUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one model call cost. Records counts and cost only, never the prompt or the reply.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $chat_message_id
 * @property LlmOperation $operation
 * @property string $provider
 * @property string $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $cost
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LlmUsage extends Model
{
    /** @use HasFactory<LlmUsageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'chat_message_id',
        'operation',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'cost',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ChatMessage, $this> */
    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => LlmOperation::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost' => MoneyCast::class,
        ];
    }
}
