<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\RecurringCandidate;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineAuditEntry;
use App\Models\PipelineRun;
use App\Models\PlannedTransaction;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class RecurringSuggestionWriter
{
    private const string STAGE_KEY = 'identify-recurring-transactions';

    private const float AMOUNT_TOLERANCE = 0.05;

    private const float DESCRIPTION_OVERLAP_THRESHOLD = 0.8;

    /**
     * @param  Collection<int, RecurringCandidate>  $candidates
     * @return list<int>
     */
    public function writeForRun(User $user, PipelineRun $run, Collection $candidates): array
    {
        $suggestionIds = [];

        foreach ($candidates as $candidate) {
            if ($this->shouldSkip($user, $candidate, $run->id)) {
                continue;
            }

            $suggestion = AnalysisSuggestion::create([
                'user_id' => $user->id,
                'pipeline_run_id' => $run->id,
                'type' => SuggestionType::RecurringTransaction,
                'status' => SuggestionStatus::Pending,
                'payload' => $candidate->toSuggestionPayload(),
            ]);

            $suggestionIds[] = $suggestion->id;
        }

        return $suggestionIds;
    }

    /**
     * Why the candidate should not be offered: noise, an accepted suggestion, an active plan already
     * covering it, or a rejection in the last 90 days. Null when it can be offered. The one place
     * these rules live, so suggestions and the plan picker never disagree.
     */
    public function skipReason(User $user, RecurringCandidate $candidate): ?string
    {
        return match (true) {
            $this->isNoise($candidate->description) => 'noise',
            $this->hasAcceptedSuggestion($user, $candidate->description, $candidate->accountId) => 'existing_accepted_suggestion',
            $this->hasMatchingPlannedTransaction(
                $user,
                $candidate->description,
                $candidate->accountId,
                $candidate->direction,
                $candidate->frequency,
                $candidate->amount,
            ) => 'existing_planned_transaction',
            $this->hasRecentRejection($user, $candidate->description, $candidate->accountId) => 'recently_rejected',
            default => null,
        };
    }

    private function shouldSkip(User $user, RecurringCandidate $candidate, int $pipelineRunId): bool
    {
        $reason = $this->skipReason($user, $candidate);

        if ($reason === null) {
            return false;
        }

        $this->createSkipAudit($pipelineRunId, $reason, $candidate->description, $candidate->accountId);

        return true;
    }

    private function isNoise(string $signature): bool
    {
        if (str_starts_with($signature, 'ROUND UP')) {
            return true;
        }

        return str_starts_with($signature, 'TRANSFER')
            && str_contains($signature, ' TO ')
            && (preg_match('/\bSAV\b/', $signature) === 1 || preg_match('/\bCC\b/', $signature) === 1);
    }

    private function hasAcceptedSuggestion(User $user, string $description, int $accountId): bool
    {
        return AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->where('status', SuggestionStatus::Accepted)
            ->where('payload->description', $description)
            ->where('payload->account_id', $accountId)
            ->exists();
    }

    private function hasMatchingPlannedTransaction(
        User $user,
        string $description,
        int $accountId,
        TransactionDirection $direction,
        RecurrenceFrequency $frequency,
        int $medianAmount,
    ): bool {
        return PlannedTransaction::query()
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('direction', $direction)
            ->where('frequency', $frequency)
            ->where('is_active', true)
            ->get()
            ->contains(fn (PlannedTransaction $planned): bool => $this->amountsMatch($planned->amount, $medianAmount)
                && $this->descriptionsRelated($planned->description, $description));
    }

    private function amountsMatch(int $plannedAmount, int $medianAmount): bool
    {
        $planned = abs($plannedAmount);
        $median = abs($medianAmount);

        if ($median === 0) {
            return $planned === 0;
        }

        return abs($planned - $median) / $median <= self::AMOUNT_TOLERANCE;
    }

    private function descriptionsRelated(string $a, string $b): bool
    {
        $tokensA = $this->descriptionTokens($a);
        $tokensB = $this->descriptionTokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return $tokensA === $tokensB;
        }

        $shared = count(array_intersect($tokensA, $tokensB));
        $smaller = min(count($tokensA), count($tokensB));

        return $shared / $smaller >= self::DESCRIPTION_OVERLAP_THRESHOLD;
    }

    /** @return list<string> */
    private function descriptionTokens(string $description): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtoupper($description), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function hasRecentRejection(User $user, string $description, int $accountId): bool
    {
        return AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::RecurringTransaction)
            ->where('status', SuggestionStatus::Rejected)
            ->where('resolved_at', '>=', now()->subDays(90))
            ->where('payload->description', $description)
            ->where('payload->account_id', $accountId)
            ->exists();
    }

    private function createSkipAudit(int $pipelineRunId, string $reason, string $description, int $accountId): void
    {
        PipelineAuditEntry::create([
            'pipeline_run_id' => $pipelineRunId,
            'stage' => self::STAGE_KEY,
            'action' => 'skipped',
            'metadata' => [
                'reason' => $reason,
                'description' => $description,
                'account_id' => $accountId,
            ],
        ]);
    }
}
