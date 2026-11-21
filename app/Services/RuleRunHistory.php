<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PipelineRunStatus;
use App\Jobs\ApplyUserRulesJob;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use Carbon\CarbonImmutable;

/**
 * The user's recent on-demand rule runs, newest first, read from the PipelineRun and the
 * PipelineAuditEntry rows ApplyUserRulesJob writes. Blocked is what a rule matched but did not
 * change: processed minus modified.
 */
final class RuleRunHistory
{
    public const int LIMIT = 10;

    /**
     * @return list<array{id: int, queued_at: CarbonImmutable|null, started_at: CarbonImmutable, trigger: string, status: string, is_running: bool, rules: list<array{name: string, processed: int, modified: int, blocked: int, failed: bool}>}>
     */
    public function forUser(int $userId, int $limit = self::LIMIT): array
    {
        $runs = PipelineRun::query()
            ->where('user_id', $userId)
            ->whereHas('auditEntries', fn ($query) => $query->where('stage', ApplyUserRulesJob::STAGE_KEY))
            ->with(['auditEntries' => fn ($query) => $query->where('stage', ApplyUserRulesJob::STAGE_KEY)->orderBy('id')])
            ->latest('started_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        return $runs->map(function (PipelineRun $run): array {
            $queuedAt = $run->auditEntries->firstWhere('action', 'queued')?->metadata['queued_at'] ?? null;

            return [
                'id' => $run->id,
                'queued_at' => is_int($queuedAt) ? CarbonImmutable::createFromTimestamp($queuedAt, config('app.timezone')) : null,
                'started_at' => $run->started_at,
                'trigger' => $run->trigger->label(),
                'status' => $run->status->label(),
                'is_running' => $run->status === PipelineRunStatus::Running,
                'rules' => $this->rules($run),
            ];
        })->all();
    }

    /**
     * @return list<array{name: string, processed: int, modified: int, blocked: int, failed: bool}>
     */
    private function rules(PipelineRun $run): array
    {
        $rules = [];

        foreach ($run->auditEntries as $entry) {
            $ruleId = $entry->metadata['rule_id'] ?? null;

            if (! in_array($entry->action, ['processed', 'rule_failed', 'baseline_timed_out'], true) || ! is_int($ruleId)) {
                continue;
            }

            $rules[$ruleId] ??= ['name' => (string) ($entry->metadata['rule_name'] ?? ''), 'processed' => 0, 'modified' => 0, 'blocked' => 0, 'failed' => false];

            if ($entry->action === 'processed') {
                $rules[$ruleId] = $this->withCounts($rules[$ruleId], $entry);
            } else {
                $rules[$ruleId]['failed'] = true;
            }
        }

        return array_values($rules);
    }

    /**
     * @param  array{name: string, processed: int, modified: int, blocked: int, failed: bool}  $line
     * @return array{name: string, processed: int, modified: int, blocked: int, failed: bool}
     */
    private function withCounts(array $line, PipelineAuditEntry $entry): array
    {
        $processed = (int) ($entry->metadata['processed'] ?? 0);
        $modified = (int) ($entry->metadata['modified'] ?? 0);

        return [...$line, 'processed' => $processed, 'modified' => $modified, 'blocked' => max(0, $processed - $modified)];
    }
}
