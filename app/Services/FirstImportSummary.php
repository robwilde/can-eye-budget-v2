<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CategorySource;
use App\Enums\RuleActionType;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRule;

final class FirstImportSummary
{
    public const int WINDOW_DAYS = 7;

    /** @return array{rules: int, categorised: int, needs_attention: int}|null */
    public function for(User $user): ?array
    {
        $run = PipelineRun::query()
            ->where('user_id', $user->id)
            ->where('completed_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereHas('auditEntries', fn ($query) => $query
                ->where('stage', 'rule-mining')
                ->where('action', 'rules_created'))
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        $mined = PipelineAuditEntry::query()
            ->where('pipeline_run_id', $run->id)
            ->where('stage', 'rule-mining')
            ->where('action', 'rules_created')
            ->first();

        /** @var list<int> $ruleIds */
        $ruleIds = UserRule::query()
            ->whereIn('id', $mined?->metadata['rule_ids'] ?? [])
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($ruleIds === []) {
            return null;
        }

        $categorised = $this->categorisedCount($user, $run, $ruleIds);

        $needsAttention = Transaction::query()
            ->where('user_id', $user->id)
            ->where('created_at', '<=', $run->completed_at)
            ->whereNull('category_id')
            ->whereDoesntHave('splits')
            ->current()
            ->excludingTransfers()
            ->count();

        return [
            'rules' => count($ruleIds),
            'categorised' => $categorised,
            'needs_attention' => $needsAttention,
        ];
    }

    /** @param  list<int>  $ruleIds */
    private function categorisedCount(User $user, PipelineRun $run, array $ruleIds): int
    {
        $ruleCategories = UserRule::query()
            ->whereIn('id', $ruleIds)
            ->get()
            ->mapWithKeys(fn (UserRule $rule): array => [$rule->id => $this->setCategoryId($rule)]);

        $applied = PipelineAuditEntry::query()
            ->whereHas('pipelineRun', fn ($query) => $query->where('user_id', $user->id))
            ->where('stage', 'user-rules')
            ->where('action', 'auto_applied')
            ->pluck('metadata')
            ->filter(fn (array $metadata): bool => in_array($metadata['rule_id'] ?? null, $ruleIds, true));

        $categoryByTransaction = Transaction::query()
            ->whereIn('id', $applied->pluck('transaction_id')->unique()->all())
            ->where('created_at', '<=', $run->completed_at)
            ->where('category_source', CategorySource::Rule->value)
            ->excludingTransfers()
            ->pluck('category_id', 'id');

        return $applied
            ->filter(fn (array $metadata): bool => isset($categoryByTransaction[$metadata['transaction_id']])
                && ($ruleCategories[$metadata['rule_id']] ?? null) !== null
                && $categoryByTransaction[$metadata['transaction_id']] === $ruleCategories[$metadata['rule_id']])
            ->pluck('transaction_id')
            ->unique()
            ->count();
    }

    private function setCategoryId(UserRule $rule): ?int
    {
        foreach ($rule->actions as $action) {
            if (($action['type'] ?? null) === RuleActionType::SetCategory->value) {
                return (int) $action['value'];
            }
        }

        return null;
    }
}
