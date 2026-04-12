<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\PipelineRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property PipelineTrigger $trigger
 * @property PipelineRunStatus $status
 * @property bool $is_first_sync
 * @property array<int, string>|null $stages_completed
 * @property array<int, string>|null $stages_skipped
 * @property array<int, mixed>|null $stages_failed
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PipelineRun extends Model
{
    /** @use HasFactory<PipelineRunFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'trigger',
        'status',
        'is_first_sync',
        'stages_completed',
        'stages_skipped',
        'stages_failed',
        'started_at',
        'completed_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AnalysisSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(AnalysisSuggestion::class);
    }

    /** @return HasMany<PipelineAuditEntry, $this> */
    public function auditEntries(): HasMany
    {
        return $this->hasMany(PipelineAuditEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => PipelineTrigger::class,
            'status' => PipelineRunStatus::class,
            'is_first_sync' => 'boolean',
            'stages_completed' => 'array',
            'stages_skipped' => 'array',
            'stages_failed' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
