<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Payees\PayeeSuggester;
use App\Support\Audit\AttributeJobToUser;
use App\Support\Rules\RuleAiCategorisation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Jev once per merchant key among the transactions a rule with the AI categorise action
 * matched. A confident answer becomes a Suggested category; anything less leaves the rows
 * uncategorised with a pending payee for the review queue. Credits, payees that are already
 * known and rows an active category rule matches are skipped by PayeeSuggester.
 *
 * Only the job user's own transactions are read, and only the digit-masked merchant name
 * leaves the app.
 */
final class CategoriseRuleMatchesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @param  list<int>  $transactionIds */
    public function __construct(public readonly User $user, public readonly array $transactionIds)
    {
        $this->afterCommit();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id),
            new WithoutOverlapping("rule-ai-user-{$this->user->id}")->releaseAfter(60)->expireAfter(300),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        if (! RuleAiCategorisation::available()) {
            return;
        }

        $suggester = app(PayeeSuggester::class);

        Transaction::query()
            ->where('user_id', $this->user->id)
            ->whereIn('id', $this->transactionIds)
            ->whereNotNull('merchant_key')
            ->current()
            ->orderBy('id')
            ->get()
            ->unique('merchant_key')
            ->each(fn (Transaction $transaction) => $suggester->suggest($this->user, $transaction));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('CategoriseRuleMatchesJob failed', [
            'userId' => $this->user->id,
            'exception' => $exception,
        ]);
    }
}
