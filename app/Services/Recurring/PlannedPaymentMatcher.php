<?php

declare(strict_types=1);

namespace App\Services\Recurring;

use App\DTOs\MatchScore;
use App\Enums\OccurrenceStatus;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Enums\TransactionStatus;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReconciliationPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scores every unlinked recent transaction against the user's open occurrences and acts on the best pairs:
 * a confident, unambiguous, posted match becomes a payment and a plan link at once, a middling or pending one
 * becomes a suggestion for the user to review, and anything weaker is left alone. Each transaction and each
 * occurrence is used by at most one pair per run, and a pair the user rejected is never proposed again.
 */
final readonly class PlannedPaymentMatcher
{
    private const int LOOKBACK_DAYS = 45;

    private const int LOOKAHEAD_DAYS = 15;

    public function __construct(private OccurrencePaymentRecorder $recorder, private PlanAmountResolver $amounts) {}

    /** @return array{linked: int, suggested: int, suggestionIds: list<int>} */
    public function matchForUser(User $user, PipelineRun $run): array
    {
        $today = $user->today();
        $occurrences = $this->openOccurrences($user, $today);
        $result = ['linked' => 0, 'suggested' => 0, 'suggestionIds' => []];

        if ($occurrences->isEmpty()) {
            return $result;
        }

        $transactions = $this->candidateTransactions($user, $today);
        $plans = $occurrences->pluck('plannedTransaction')->unique('id')->keyBy('id');
        $history = $this->amounts->forPlans($plans);
        $plainIds = Transaction::query()->excludingTransfers()->whereKey($transactions->pluck('id')->all())->pluck('id')->flip();
        $rejected = PlannedMatchRejection::query()->where('user_id', $user->id)->get(['planned_transaction_id', 'transaction_id'])
            ->mapWithKeys(static fn (PlannedMatchRejection $row): array => ["{$row->planned_transaction_id}:{$row->transaction_id}" => true]);

        $pairs = [];
        $byTransaction = [];

        foreach ($transactions as $transaction) {
            foreach ($occurrences as $occurrence) {
                $plan = $occurrence->plannedTransaction;

                if ($transaction->account_id !== $plan->account_id
                    || $transaction->direction !== $plan->direction
                    || isset($rejected["{$plan->id}:{$transaction->id}"])
                    || ! $this->fitsLegs($plan, $transaction, $plainIds)) {
                    continue;
                }

                $expected = $occurrence->expected_amount ?? $history[$plan->id] ?? $plan->amount;
                $score = ReconciliationPolicy::score($occurrence, $transaction, $today, $expected);

                if ($score === null || $score->total() < ReconciliationPolicy::SUGGEST_SCORE) {
                    continue;
                }

                $pairs[] = ['transaction' => $transaction, 'occurrence' => $occurrence, 'score' => $score->total(), 'signals' => $score];
                $byTransaction[$transaction->id][$occurrence->id] = $score->total();
            }
        }

        usort($pairs, static fn (array $a, array $b): int => [$b['score'], $a['occurrence']->id, $a['transaction']->id] <=> [$a['score'], $b['occurrence']->id, $b['transaction']->id]);

        $usedTransactions = [];
        $usedOccurrences = [];

        foreach ($pairs as $pair) {
            ['transaction' => $transaction, 'occurrence' => $occurrence, 'score' => $score, 'signals' => $signals] = $pair;

            if (isset($usedTransactions[$transaction->id]) || isset($usedOccurrences[$occurrence->id])) {
                continue;
            }

            $others = array_diff_key($byTransaction[$transaction->id], [$occurrence->id => true], $usedOccurrences);
            $lead = round($score - ($others === [] ? 0.0 : max($others)), 4);
            $confident = $score >= ReconciliationPolicy::AUTO_LINK_SCORE
                && $transaction->status === TransactionStatus::Posted
                && $lead >= ReconciliationPolicy::AUTO_LINK_LEAD;

            if ($confident && $this->link($user, $transaction, $occurrence)) {
                $result['linked']++;
            } elseif ($occurrence->plannedTransaction->direction === TransactionDirection::Debit) {
                $result['suggestionIds'][] = $this->suggest($user, $run, $transaction, $occurrence, $score, $signals);
                $result['suggested']++;
            } else {
                continue;
            }

            $usedTransactions[$transaction->id] = true;
            $usedOccurrences[$occurrence->id] = true;
        }

        return $result;
    }

    /**
     * Links a transaction to a plan the amount-and-date rules chose, pays the open occurrence nearest its post
     * date and settles any suggestion for it, all in one database transaction. Used where there is no scored
     * occurrence: the ingestor and the backstop matcher.
     */
    public function linkToPlan(Transaction $transaction, PlannedTransaction $plan): void
    {
        DB::transaction(function () use ($transaction, $plan): void {
            $transaction->update(['planned_transaction_id' => $plan->id]);
            app(PaymentSettlementDeferral::class)->transactionLinked($transaction);
            $this->supersedeSuggestions($plan->user, $transaction->id);
        });
    }

    public function supersedeSuggestions(User $user, int $transactionId, ?int $except = null): void
    {
        AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->where('payload->transaction_id', $transactionId)
            ->when($except !== null, fn (Builder $query): Builder => $query->whereKeyNot($except))
            ->update(['status' => SuggestionStatus::Superseded, 'resolved_at' => now()]);
    }

    /** @return Collection<int, PlannedOccurrence> */
    private function openOccurrences(User $user, CarbonImmutable $today): Collection
    {
        return PlannedOccurrence::query()
            ->where('status', OccurrenceStatus::Scheduled->value)
            ->whereDate('due_date', '>=', $today->subDays(self::LOOKBACK_DAYS + self::LOOKAHEAD_DAYS)->toDateString())
            ->whereDate('due_date', '<=', $today->addDays(self::LOOKAHEAD_DAYS)->toDateString())
            ->whereHas('plannedTransaction', static fn (Builder $plans): Builder => $plans->where('user_id', $user->id)->where('is_active', true))
            ->with('plannedTransaction')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Transaction> */
    private function candidateTransactions(User $user, CarbonImmutable $today): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->current()
            ->where('excluded', false)
            ->whereNull('planned_transaction_id')
            ->whereNull('split_parent_id')
            ->whereNotExists(static fn ($payments) => $payments->selectRaw('1')->from('planned_payments')->whereColumn('planned_payments.transaction_id', 'transactions.id'))
            ->whereDate('post_date', '>=', $today->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->whereDate('post_date', '<=', $today->addDays(3)->toDateString())
            ->with('transferPair:id,account_id')
            ->orderBy('id')
            ->get();
    }

    /** @param  Collection<int, int>  $plainIds */
    private function fitsLegs(PlannedTransaction $plan, Transaction $transaction, Collection $plainIds): bool
    {
        if ($plan->transfer_to_account_id === null) {
            return $plainIds->has($transaction->id);
        }

        return $transaction->transfer_pair_id !== null && $transaction->transferPair?->account_id === $plan->transfer_to_account_id;
    }

    private function link(User $user, Transaction $transaction, PlannedOccurrence $occurrence): bool
    {
        try {
            DB::transaction(function () use ($user, $transaction, $occurrence): void {
                $this->recorder->attach($user, $transaction->id, $occurrence->id);

                $transaction->update(['planned_transaction_id' => $occurrence->planned_transaction_id]);

                $this->supersedeSuggestions($user, $transaction->id);
            });
        } catch (ValidationException|ModelNotFoundException) {
            return false;
        }

        return true;
    }

    private function suggest(User $user, PipelineRun $run, Transaction $transaction, PlannedOccurrence $occurrence, float $score, MatchScore $signals): int
    {
        $existing = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->where('payload->transaction_id', $transaction->id)
            ->where('payload->planned_occurrence_id', $occurrence->id)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return AnalysisSuggestion::query()->create([
            'user_id' => $user->id,
            'pipeline_run_id' => $run->id,
            'type' => SuggestionType::PlannedPaymentMatch,
            'status' => SuggestionStatus::Pending,
            'payload' => [
                'transaction_id' => $transaction->id,
                'planned_transaction_id' => $occurrence->planned_transaction_id,
                'planned_occurrence_id' => $occurrence->id,
                'account_id' => $occurrence->plannedTransaction->account_id,
                'score' => $score,
                'signals' => ['identity' => $signals->identity, 'amount' => $signals->amount, 'date' => $signals->date, 'account' => $signals->account],
            ],
        ])->id;
    }
}
