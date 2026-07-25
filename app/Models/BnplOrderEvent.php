<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BnplOrderEventType;
use Carbon\CarbonImmutable;
use Database\Factories\BnplOrderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $bnpl_order_id
 * @property BnplOrderEventType $event
 * @property array<string, mixed>|null $payload
 * @property string|null $actor
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BnplOrderEvent extends Model
{
    /** @use HasFactory<BnplOrderEventFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bnpl_order_id',
        'event',
        'payload',
        'actor',
    ];

    /** @return BelongsTo<BnplOrder, $this> */
    public function bnplOrder(): BelongsTo
    {
        return $this->belongsTo(BnplOrder::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => BnplOrderEventType::class,
            'payload' => 'array',
        ];
    }
}
