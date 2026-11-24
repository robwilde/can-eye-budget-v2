<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a user decides about a suggested plan payment: confirm it (pay the occurrence, link the transaction to
 * the plan and accept the suggestion together), reject it (the pair is never proposed again), or undo a link
 * that was made automatically. Every step re-scopes to the user and runs in one database transaction.
 */
final readonly class PlannedPaymentReview
{
    public function __construct(private OccurrencePaymentRecorder $recorder, private PlannedPaymentMatcher $matcher) {}

    /**
     * @throws ModelNotFoundException when the suggestion, its transaction or its occurrence is not the user's
     * @throws ValidationException when the payment is refused; the suggestion stays pending
     */
    public function confirm(User $user, int $suggestionId): void
    {
        $suggestion = $this->pending($user, $suggestionId);
        $transactionId = (int) $suggestion->payload['transaction_id'];
        $occurrenceId = (int) $suggestion->payload['planned_occurrence_id'];

        DB::transaction(function () use ($user, $suggestion, $transactionId, $occurrenceId): void {
            $transaction = Transaction::query()->where('user_id', $user->id)->current()->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $planId = PlannedOccurrence::query()->forUser($user)->whereKey($occurrenceId)->value('planned_transaction_id')
                ?? throw (new ModelNotFoundException)->setModel(PlannedOccurrence::class);

            $this->requireUnclaimed($transaction, $planId);

            $this->recorder->attach($user, $transactionId, $occurrenceId);

            if ($transaction->planned_transaction_id !== $planId) {
                $transaction->update(['planned_transaction_id' => $planId]);
            }

            $this->matcher->supersedeSuggestions($user, $transactionId, except: $suggestion->id);

            $this->resolve($suggestion, SuggestionStatus::Accepted);
        });
    }

    /** @throws ModelNotFoundException */
    public function reject(User $user, int $suggestionId): void
    {
        DB::transaction(function () use ($user, $suggestionId): void {
            $suggestion = $this->pending($user, $suggestionId);

            PlannedMatchRejection::query()->firstOrCreate([
                'user_id' => $user->id,
                'planned_transaction_id' => (int) $suggestion->payload['planned_transaction_id'],
                'transaction_id' => (int) $suggestion->payload['transaction_id'],
            ]);

            $this->resolve($suggestion, SuggestionStatus::Rejected);
        });
    }

    /**
     * Removes the payment a linked transaction made, clears its plan link and remembers the pair as rejected, so
     * the occurrence reopens and matching does not link it again.
     *
     * @throws ModelNotFoundException
     */
    public function unlink(User $user, int $paymentId): void
    {
        $payment = PlannedPayment::query()->forUser($user)->with('occurrence')->whereKey($paymentId)->firstOrFail();

        $transactionId = $payment->transaction_id ?? throw (new ModelNotFoundException)->setModel(Transaction::class);
        $planId = $payment->occurrence->planned_transaction_id;

        DB::transaction(function () use ($user, $payment, $transactionId, $planId): void {
            $transaction = Transaction::query()->where('user_id', $user->id)->whereKey($transactionId)->lockForUpdate()->firstOrFail();
            $this->recorder->lockPlan($planId);

            PlannedMatchRejection::query()->firstOrCreate([
                'user_id' => $user->id,
                'planned_transaction_id' => $planId,
                'transaction_id' => $transactionId,
            ]);

            $this->recorder->removePayment($user, $payment->id);

            if ($transaction->planned_transaction_id === $planId) {
                $transaction->update(['planned_transaction_id' => null]);
            }
        });
    }

    /**
     * A suggestion is only good while its transaction is unlinked or linked to the suggested plan and has paid
     * nothing on any other plan: confirming it would otherwise take that payment back.
     *
     * @throws ValidationException
     */
    private function requireUnclaimed(Transaction $transaction, int $planId): void
    {
        $paidElsewhere = PlannedPayment::query()
            ->where('transaction_id', $transaction->id)
            ->whereHas('occurrence', fn ($occurrence) => $occurrence->where('planned_transaction_id', '!=', $planId))
            ->exists();

        if ($paidElsewhere || ($transaction->planned_transaction_id !== null && $transaction->planned_transaction_id !== $planId)) {
            throw ValidationException::withMessages(['plannedPayment' => __('This transaction has since been applied to another bill. Reject this suggestion instead.')]);
        }
    }

    /**
     * Settles a suggestion only if it is still pending, so two decisions at once cannot both win. The
     * suggestion row is written last, after the transaction, plan and occurrence locks, as every matcher does.
     */
    private function resolve(AnalysisSuggestion $suggestion, SuggestionStatus $status): void
    {
        $settled = AnalysisSuggestion::query()->whereKey($suggestion->id)->pending()->update(['status' => $status, 'resolved_at' => now()]);

        if ($settled === 0) {
            throw (new ModelNotFoundException)->setModel(AnalysisSuggestion::class, [$suggestion->id]);
        }
    }

    private function pending(User $user, int $suggestionId): AnalysisSuggestion
    {
        return AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->whereKey($suggestionId)
            ->firstOrFail();
    }
}
