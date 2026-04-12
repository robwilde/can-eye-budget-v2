<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use Carbon\CarbonImmutable;
use Database\Factories\AnalysisSuggestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $pipeline_run_id
 * @property SuggestionType $type
 * @property SuggestionStatus $status
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AnalysisSuggestion extends Model
{
    /** @use HasFactory<AnalysisSuggestionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'pipeline_run_id',
        'type',
        'status',
        'payload',
        'resolved_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PipelineRun, $this> */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    /**
     * @param  Builder<AnalysisSuggestion>  $query
     * @return Builder<AnalysisSuggestion>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SuggestionStatus::Pending);
    }

    /**
     * @param  Builder<AnalysisSuggestion>  $query
     * @return Builder<AnalysisSuggestion>
     */
    public function scopeOfType(Builder $query, SuggestionType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SuggestionType::class,
            'status' => SuggestionStatus::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
        ];
    }
}
