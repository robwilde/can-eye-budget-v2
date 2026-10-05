<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\PayFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\PayCycleConfigurator;
use Throwable;

/**
 * Saves the primary account and pay cycle the user confirmed during onboarding.
 *
 * The pay cycle is written through PayCycleConfigurator, the same path the settings
 * page uses. Pending primary-account and pay-cycle suggestions are resolved: accepted
 * when the confirmed values match what was detected, rejected when the user changed them.
 */
final readonly class ConfirmPayCycle
{
    public function __construct(
        private PayCycleConfigurator $configurator,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(
        User $user,
        Account $account,
        int $payAmountCents,
        PayFrequency $frequency,
        string $nextPayDate,
    ): void {
        $user->getConnection()->transaction(function () use ($user, $account, $payAmountCents, $frequency, $nextPayDate): void {
            $suggestions = AnalysisSuggestion::query()
                ->where('user_id', $user->id)
                ->pending()
                ->whereIn('type', [SuggestionType::PrimaryAccount, SuggestionType::PayCycle])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $user->update(['primary_account_id' => $account->id]);

            $payCycle = $suggestions->last(fn (AnalysisSuggestion $s): bool => $s->type === SuggestionType::PayCycle);
            $detectedOnAccount = $payCycle !== null && ($payCycle->payload['source_account_id'] ?? null) === $account->id;

            /** @var list<int> $sourceTransactionIds */
            $sourceTransactionIds = $detectedOnAccount ? ($payCycle->payload['source_transaction_ids'] ?? []) : [];

            $description = $detectedOnAccount
                ? ($payCycle->payload['source_description'] ?? null)
                : PlannedTransaction::query()
                    ->where('user_id', $user->id)
                    ->where('account_id', $account->id)
                    ->where('is_pay_cycle_income', true)
                    ->value('description');

            $this->configurator->apply(
                $user,
                $payAmountCents,
                $frequency,
                $nextPayDate,
                $description,
                $sourceTransactionIds,
            );

            foreach ($suggestions as $suggestion) {
                $matches = $suggestion->type === SuggestionType::PrimaryAccount
                    ? ($suggestion->payload['account_id'] ?? null) === $account->id
                    : ($suggestion->payload['source_account_id'] ?? null) === $account->id
                        && ($suggestion->payload['pay_amount'] ?? null) === $payAmountCents
                        && ($suggestion->payload['pay_frequency'] ?? null) === $frequency->value
                        && ($suggestion->payload['next_pay_date'] ?? null) === $nextPayDate;

                $suggestion->update([
                    'status' => $matches ? SuggestionStatus::Accepted : SuggestionStatus::Rejected,
                    'resolved_at' => now(),
                ]);
            }
        });
    }
}
