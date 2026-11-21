<?php

declare(strict_types=1);

namespace App\Support\Rules;

/**
 * Tracks, across the rules of one on-demand run, which transactions a group with stop-processing
 * has already matched, as UserRulesStage does per row: once a stop group matches a row, the groups
 * below it leave that row alone. Rules inside one group never stop each other.
 */
final class RuleRunScope
{
    /** @var array<int, true> */
    private array $stopped = [];

    /** @var array<int, true> */
    private array $groupMatched = [];

    public function isStopped(int $transactionId): bool
    {
        return isset($this->stopped[$transactionId]);
    }

    public function noteMatch(int $transactionId): void
    {
        $this->groupMatched[$transactionId] = true;
    }

    public function closeGroup(bool $stopProcessing): void
    {
        if ($stopProcessing) {
            $this->stopped += $this->groupMatched;
        }

        $this->groupMatched = [];
    }
}
