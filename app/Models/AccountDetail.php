<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\LoanRateType;
use Carbon\CarbonImmutable;
use Database\Factories\AccountDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property LoanRateType|null $rate_type
 * @property string|null $rate
 * @property int|null $term_months
 * @property CarbonImmutable|null $start_date
 * @property int|null $original_principal
 * @property int|null $down_payment
 * @property int|null $insurance
 * @property int|null $minimum_payment
 * @property int|null $annual_fee
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 */
final class AccountDetail extends Model
{
    /** @use HasFactory<AccountDetailFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'rate_type',
        'rate',
        'term_months',
        'start_date',
        'original_principal',
        'down_payment',
        'insurance',
        'minimum_payment',
        'annual_fee',
    ];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_type' => LoanRateType::class,
            'rate' => 'decimal:3',
            'term_months' => 'integer',
            'start_date' => 'immutable_date',
            'original_principal' => MoneyCast::class,
            'down_payment' => MoneyCast::class,
            'insurance' => MoneyCast::class,
            'minimum_payment' => MoneyCast::class,
            'annual_fee' => MoneyCast::class,
        ];
    }
}
