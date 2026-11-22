<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SecuritySplitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock split: from the date on, each share becomes `ratio` shares (2 for a 2-for-1 split, 0.1 for
 * a 1-for-10 consolidation). The ratio is an exact decimal string; never convert it to a float.
 *
 * @property int $id
 * @property int $security_id
 * @property CarbonImmutable $date
 * @property string $ratio
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class SecuritySplit extends Model
{
    /** @use HasFactory<SecuritySplitFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'security_id',
        'date',
        'ratio',
    ];

    /** @return BelongsTo<Security, $this> */
    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'ratio' => 'decimal:8',
        ];
    }
}
