<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransactionEmailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $transaction_id
 * @property string $gmail_message_id
 * @property string $subject
 * @property string|null $from_name
 * @property string $from_address
 * @property CarbonImmutable|null $email_date
 * @property string|null $snippet
 * @property string $gmail_url
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TransactionEmail extends Model
{
    /** @use HasFactory<TransactionEmailFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'transaction_id',
        'gmail_message_id',
        'subject',
        'from_name',
        'from_address',
        'email_date',
        'snippet',
        'gmail_url',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_date' => 'datetime',
        ];
    }
}
