<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CategoriseRuleMatchesJob;
use App\Models\Transaction;
use App\Support\Rules\RuleAiCategorisation;

/**
 * Hands the transactions a rule matched to the background categoriser. It only queues: no
 * TypeSafe call is made here, so previews and in-request applies stay offline. Bound as a
 * scoped service, it queues each user's merchant key once per request or queue job.
 */
final class RuleAiCategorisationQueue
{
    /** @var array<string, true> */
    private array $queued = [];

    public function queue(Transaction $transaction): bool
    {
        if (! RuleAiCategorisation::available() || $transaction->merchant_key === null) {
            return false;
        }

        $key = $transaction->user_id.':'.$transaction->merchant_key;

        if (isset($this->queued[$key])) {
            return true;
        }

        $this->queued[$key] = true;

        CategoriseRuleMatchesJob::dispatch($transaction->user, [$transaction->id]);

        return true;
    }
}
