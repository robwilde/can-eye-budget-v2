<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\RuleBacklogApplier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Clears a user's uncategorised backlog with their existing rules, off the web
 * request.
 *
 * Queued rather than synchronous because the sweep evaluates every active rule
 * against every uncategorised row: measured at roughly 200 rules x 800 rows on
 * a real database, that is well past what belongs in a page load, even though a
 * single-rule sweep (#453) is only ~48ms.
 */
final class ApplyRuleBacklogJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(RuleBacklogApplier $applier): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $result = $applier->apply($user);

        Log::info('Rule backlog applied', [
            'user_id' => $user->id,
            'scanned' => $result['scanned'],
            'categorised' => $result['categorised'],
        ]);
    }
}
