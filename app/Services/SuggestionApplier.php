<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountClass;
use App\Enums\CategorySource;
use App\Enums\PayFrequency;
use App\Enums\PlannedTransactionStatus;
use App\Enums\PlanOrigin;
use App\Enums\SuggestionStatus;
use App\Enums\TransactionDirection;
use App\Models\AnalysisSuggestion;
use App\Models\Payee;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payees\PayeeConfirmer;
use App\Services\Recurring\OccurrencePaymentRecorder;
use App\Services\Recurring\PlanTypeClassifier;
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
        private PayeeConfirmer $payeeConfirmer,
    ) {}

    /**
     * Returns false and changes nothing when the suggested account is not an eligible
     * primary account of this user.
     */
    public function applyPrimaryAccount(AnalysisSuggestion $suggestion, User $user): bool
    {
        $account = $user->eligiblePrimaryAccount((int) ($suggestion->payload['account_id'] ?? 0));

        if ($account === null) {
            return false;
        }

        $user->update(['primary_account_id' => $account->id]);

        $this->markAccepted($suggestion);

        return true;
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
            $type = app(PlanTypeClassifier::class)->classify(
                (string) $payload['clean_description'],
                TransactionDirection::from($payload['direction']),
                $this->isFlatCardCharge($payload['matched_transaction_ids'] ?? [], $user),
            );

            $planned = PlannedTransaction::create([
                'user_id' => $user->id,
                'account_id' => $payload['account_id'],
                'amount' => $payload['amount'],
                'direction' => $payload['direction'],
                'type' => $type,
                'autopay' => app(PlanTypeClassifier::class)->defaultAutopay($type),
                'origin' => PlanOrigin::Detected,
                'description' => $payload['clean_description'],
                'start_date' => $payload['start_date'],
                'frequency' => $payload['frequency'],
                'status' => PlannedTransactionStatus::Active,
                'category_id' => $categoryId,
            ]);

            $matchedIds = $payload['matched_transaction_ids'] ?? [];

            if ($matchedIds !== []) {
                $updateData = ['planned_transaction_id' => $planned->id];

                if ($categoryId !== null) {
                    $updateData['category_id'] = $categoryId;
                    $updateData['category_source'] = CategorySource::Manual->value;
                }

                Transaction::whereIn('id', $matchedIds)
                    ->where('user_id', $user->id)
                    ->update($updateData);

                if ($categoryId !== null) {
                    $this->categoriseMerchantsOf($matchedIds, $user, $categoryId);
                }

                $this->claimOccurrences($matchedIds, $user, $planned);
            }

            $this->markAccepted($suggestion);

            return $planned;
        });
    }

    /**
     * The user picked this category for the recurring merchant, so every one of
     * that merchant's transactions follows it, not just the matched pattern.
     * A merchant with a Payee row goes through PayeeConfirmer so a rule and
     * tag exist too; otherwise the rows are written directly.
     *
     * @param  list<int>  $matchedIds
     */
    private function categoriseMerchantsOf(array $matchedIds, User $user, int $categoryId): void
    {
        $merchantKeys = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $matchedIds)
            ->whereNotNull('merchant_key')
            ->distinct()
            ->pluck('merchant_key');

        foreach ($merchantKeys as $merchantKey) {
            $payee = Payee::query()
                ->where('user_id', $user->id)
                ->where('merchant_key', $merchantKey)
                ->first();

            if ($payee !== null) {
                $this->payeeConfirmer->confirm($user, $payee, $categoryId);

                continue;
            }

            $this->payeeConfirmer->updateTransactionsForMerchantKey($user, $merchantKey, $categoryId);
        }
    }

    /**
     * Each matched charge pays the occurrence of the new plan it falls on, so the history shows as paid.
     *
     * @param  list<int>  $matchedIds
     */
    private function claimOccurrences(array $matchedIds, User $user, PlannedTransaction $planned): void
    {
        app(OccurrencePaymentRecorder::class)->claimAll(
            Transaction::query()->where('user_id', $user->id)->current()->whereKey($matchedIds)->get(),
            $planned,
        );
    }

    /**
     * @param  list<int>  $matchedIds
     */
    private function isFlatCardCharge(array $matchedIds, User $user): bool
    {
        $rows = Transaction::query()->where('user_id', $user->id)->whereKey($matchedIds)->with('account:id,type')->get(['id', 'account_id', 'amount']);

        return $rows->isNotEmpty()
            && $rows->every(static fn (Transaction $row): bool => $row->account?->type === AccountClass::CreditCard)
            && $rows->pluck('amount')->unique()->count() === 1
            && abs((int) $rows->first()->amount) <= PlanTypeClassifier::FLAT_CARD_CHARGE_LIMIT_CENTS;
    }

    private function markAccepted(AnalysisSuggestion $suggestion): void
    {
        $suggestion->update([
            'status' => SuggestionStatus::Accepted,
            'resolved_at' => now(),
        ]);
    }
}
