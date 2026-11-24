<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\Enums\OccurrenceStatus;
use App\Enums\PlannedTransactionStatus;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
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

    /**
     * Pending suggested plan payments that can still be confirmed, most confident first, each with the plan,
     * transaction and occurrence it names: the transaction is current, unlinked and unpaid, the occurrence open
     * and its plan active. With an account, only that account's. The one rule the rules page and Bills share.
     *
     * @return Collection<int, array{suggestion: AnalysisSuggestion, plan: PlannedTransaction, transaction: Transaction, occurrence: PlannedOccurrence}>
     */
    public function actionable(User $user, ?int $accountId = null): Collection
    {
        $suggestions = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->when($accountId !== null, fn ($query) => $query->where('payload->account_id', $accountId))
            ->get()
            ->sortBy([fn (AnalysisSuggestion $a, AnalysisSuggestion $b): int => ((float) ($b->payload['score'] ?? 0)) <=> ((float) ($a->payload['score'] ?? 0)), fn (AnalysisSuggestion $a, AnalysisSuggestion $b): int => $a->id <=> $b->id]);

        $transactions = Transaction::query()->where('user_id', $user->id)->current()->whereNull('planned_transaction_id')->whereDoesntHave('payments')->whereKey($suggestions->pluck('payload.transaction_id')->all())->get()->keyBy('id');
        $occurrences = PlannedOccurrence::query()->forUser($user)->where('status', OccurrenceStatus::Scheduled->value)->whereHas('plannedTransaction', fn ($plans) => $plans->where('status', PlannedTransactionStatus::Active->value))->with('plannedTransaction')->whereKey($suggestions->pluck('payload.planned_occurrence_id')->all())->get()->keyBy('id');

        return $suggestions
            ->map(static function (AnalysisSuggestion $suggestion) use ($transactions, $occurrences): ?array {
                $transaction = $transactions->get($suggestion->payload['transaction_id'] ?? 0);
                $occurrence = $occurrences->get($suggestion->payload['planned_occurrence_id'] ?? 0);

                return $transaction instanceof Transaction && $occurrence instanceof PlannedOccurrence
                    ? ['suggestion' => $suggestion, 'plan' => $occurrence->plannedTransaction, 'transaction' => $transaction, 'occurrence' => $occurrence]
                    : null;
            })
            ->filter()
            ->values();
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
