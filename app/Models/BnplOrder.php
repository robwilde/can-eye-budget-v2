<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\BnplOrderEventType;
use App\Enums\BnplOrderStatus;
use App\Enums\BnplProvider;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\BnplOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property BnplProvider $provider
 * @property string $retailer
 * @property string $order_ref
 * @property int $total
 * @property int $instalment_amount
 * @property int $instalment_count
 * @property CarbonImmutable $first_due_date
 * @property CarbonImmutable $last_due_date
 * @property RecurrenceFrequency|null $frequency
 * @property int|null $account_id
 * @property int|null $category_id
 * @property string|null $card_last4
 * @property BnplOrderStatus $status
 * @property string|null $review_note
 * @property int|null $planned_transaction_id
 * @property string $gmail_message_id
 * @property string $subject
 * @property CarbonImmutable|null $email_date
 * @property string|null $snippet
 * @property string $gmail_url
 * @property array<string, mixed>|null $parsed_payload
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BnplOrder extends Model
{
    /** @use HasFactory<BnplOrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'retailer',
        'order_ref',
        'total',
        'instalment_amount',
        'instalment_count',
        'first_due_date',
        'last_due_date',
        'frequency',
        'account_id',
        'category_id',
        'card_last4',
        'status',
        'review_note',
        'planned_transaction_id',
        'gmail_message_id',
        'subject',
        'email_date',
        'snippet',
        'gmail_url',
        'parsed_payload',
        'reviewed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<PlannedTransaction, $this> */
    public function plannedTransaction(): BelongsTo
    {
        return $this->belongsTo(PlannedTransaction::class);
    }

    /** @return HasMany<BnplOrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BnplOrderEvent::class);
    }

    /**
     * The single write path for this order's audit trail. Callers never build a
     * BnplOrderEvent directly, so the event vocabulary stays closed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(BnplOrderEventType $event, array $payload = [], string $actor = 'system'): BnplOrderEvent
    {
        return $this->events()->create([
            'event' => $event,
            'payload' => $payload === [] ? null : $payload,
            'actor' => $actor,
        ]);
    }

    /**
     * A settled schedule has no instalment left to forecast, so it seeds the
     * retailer category memory without ever producing a planned transaction.
     */
    public function isSettled(): bool
    {
        return $this->last_due_date->lessThan(CarbonImmutable::today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => BnplProvider::class,
            'status' => BnplOrderStatus::class,
            'frequency' => RecurrenceFrequency::class,
            'total' => MoneyCast::class,
            'instalment_amount' => MoneyCast::class,
            'first_due_date' => 'date',
            'last_due_date' => 'date',
            'email_date' => 'datetime',
            'reviewed_at' => 'datetime',
            'parsed_payload' => 'array',
        ];
    }
}
