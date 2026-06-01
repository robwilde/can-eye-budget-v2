<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayFrequency;
use App\Enums\SuggestionStatus;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies an accepted (or auto-applied) analysis suggestion to the user's data.
 *
 * Lives outside the Livewire layer so both the suggestions UI and the queued
 * analysis pipeline can apply suggestions through the same code path. Each
 * method takes an explicit User rather than relying on auth() context.
 */
final readonly class SuggestionApplier
{
    public function __construct(
        private PayCycleConfigurator $payCycleConfigurator,
    ) {}

    public function applyPrimaryAccount(AnalysisSuggestion $suggestion, User $user): void
    {
        $user->update(['primary_account_id' => $suggestion->payload['account_id']]);

        $this->markAccepted($suggestion);
    }

    /**
     * @throws Throwable
     */
    public function applyPayCycle(
        AnalysisSuggestion $suggestion,
        User $user,
        int $payAmount,
        string $payFrequency,
        string $nextPayDate,
    ): void {
        /** @var list<int> $sourceTransactionIds */
        $sourceTransactionIds = $suggestion->payload['source_transaction_ids'] ?? [];

        $this->payCycleConfigurator->apply(
            $user,
            $payAmount,
            PayFrequency::from($payFrequency),
            $nextPayDate,
            $suggestion->payload['source_description'] ?? null,
            $sourceTransactionIds,
        );

        $this->markAccepted($suggestion);
    }

    /**
     * @throws Throwable
     */
    public function applyRecurringTransaction(
        AnalysisSuggestion $suggestion,
        User $user,
        ?int $categoryId,
    ): PlannedTransaction {
        return DB::transaction(function () use ($suggestion, $user, $categoryId): PlannedTransaction {
            $payload = $suggestion->payload;

            $planned = PlannedTransaction::create([
                'user_id' => $user->id,
                'account_id' => $payload['account_id'],
                'amount' => $payload['amount'],
                'direction' => $payload['direction'],
                'description' => $payload['clean_description'],
                'start_date' => $payload['start_date'],
                'frequency' => $payload['frequency'],
                'is_active' => true,
                'category_id' => $categoryId,
            ]);

            $matchedIds = $payload['matched_transaction_ids'] ?? [];

            if ($matchedIds !== []) {
                $updateData = ['planned_transaction_id' => $planned->id];

                if ($categoryId !== null) {
                    $updateData['category_id'] = $categoryId;
                }

                Transaction::whereIn('id', $matchedIds)
                    ->where('user_id', $user->id)
                    ->update($updateData);
            }

            $this->markAccepted($suggestion);

            return $planned;
        });
    }

    private function markAccepted(AnalysisSuggestion $suggestion): void
    {
        $suggestion->update([
            'status' => SuggestionStatus::Accepted,
            'resolved_at' => now(),
        ]);
    }
}
