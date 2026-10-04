<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property AuditOutcome $outcome
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
final class AuditEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'outcome',
        'request_id',
        'created_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(config()->integer('audit.retention_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => AuditOutcome::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
