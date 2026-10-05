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
use Carbon\CarbonInterface;

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

        $needsAttention = $this->needsAttentionCount($user, $run);

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

        $importedIds = Transaction::query()
            ->whereIn('id', $applied->pluck('transaction_id')->unique()->all())
            ->where('created_at', '<=', $run->completed_at)
            ->pluck('id')
            ->all();

        $currentIdByOriginal = $this->currentVersionIds($importedIds);

        $categoryByCurrent = Transaction::query()
            ->whereIn('id', array_values($currentIdByOriginal))
            ->where('category_source', CategorySource::Rule->value)
            ->excludingTransfers()
            ->pluck('category_id', 'id');

        $categoryByTransaction = collect($currentIdByOriginal)
            ->filter(fn (int $currentId): bool => isset($categoryByCurrent[$currentId]))
            ->map(fn (int $currentId): int => $categoryByCurrent[$currentId]);

        return $applied
            ->filter(fn (array $metadata): bool => isset($categoryByTransaction[$metadata['transaction_id']])
                && ($ruleCategories[$metadata['rule_id']] ?? null) !== null
                && $categoryByTransaction[$metadata['transaction_id']] === $ruleCategories[$metadata['rule_id']])
            ->pluck('transaction_id')
            ->unique()
            ->count();
    }

    private function needsAttentionCount(User $user, PipelineRun $run): int
    {
        $candidates = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereDoesntHave('splits')
            ->current()
            ->excludingTransfers()
            ->where(fn ($query) => $query
                ->whereNotNull('parent_transaction_id')
                ->orWhere('created_at', '<=', $run->completed_at))
            ->get(['id', 'parent_transaction_id', 'created_at']);

        $versioned = $candidates->whereNotNull('parent_transaction_id');
        $rootCreatedAt = $this->rootCreatedAt($versioned->pluck('parent_transaction_id')->all());

        return $candidates->filter(function (Transaction $transaction) use ($run, $rootCreatedAt): bool {
            $createdAt = $transaction->parent_transaction_id === null
                ? $transaction->created_at
                : $rootCreatedAt[$transaction->parent_transaction_id] ?? null;

            return $createdAt !== null && $createdAt <= $run->completed_at;
        })->count();
    }

    /**
     * @param  list<int>  $parentIds
     * @return array<int, CarbonInterface> created_at of each starting id's lineage root
     */
    private function rootCreatedAt(array $parentIds): array
    {
        $result = [];
        $pending = array_fill_keys($parentIds, null);
        $lookup = $parentIds;

        while ($lookup !== []) {
            $rows = Transaction::withTrashed()
                ->whereIn('id', array_unique($lookup))
                ->get(['id', 'parent_transaction_id', 'created_at'])
                ->keyBy('id');

            $lookup = [];

            foreach ($pending as $start => $cursor) {
                $row = $rows[$cursor ?? $start] ?? null;

                if ($row === null) {
                    unset($pending[$start]);

                    continue;
                }

                if ($row->parent_transaction_id === null) {
                    $result[$start] = $row->created_at;
                    unset($pending[$start]);

                    continue;
                }

                $pending[$start] = $row->parent_transaction_id;
                $lookup[] = $row->parent_transaction_id;
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int> original id => id of its current version
     */
    private function currentVersionIds(array $ids): array
    {
        $current = array_combine($ids, $ids);
        $frontier = $current;

        while ($frontier !== []) {
            $children = Transaction::query()
                ->whereIn('parent_transaction_id', array_values($frontier))
                ->orderBy('id')
                ->pluck('id', 'parent_transaction_id');

            $next = [];

            foreach ($frontier as $original => $cursor) {
                if (isset($children[$cursor])) {
                    $current[$original] = (int) $children[$cursor];
                    $next[$original] = (int) $children[$cursor];
                }
            }

            $frontier = $next;
        }

        return $current;
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
