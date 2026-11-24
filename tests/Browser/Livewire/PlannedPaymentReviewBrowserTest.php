<?php

/** @noinspection JSUnresolvedReference */
/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\OccurrenceStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Enums\TransactionDirection;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\PlannedMatchRejection;
use App\Models\PlannedOccurrence;
use App\Models\PlannedPayment;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;

function browserSuggestion(User $user, Account $account, string $description, float $score): array
{
    $transaction = Transaction::factory()->for($user)->for($account)->create(['description' => mb_strtoupper($description).' BILL', 'amount' => -10400, 'direction' => TransactionDirection::Debit, 'post_date' => now()->toDateString(), 'planned_transaction_id' => null]);
    $plan = PlannedTransaction::factory()->for($user)->for($account)->create(['description' => $description, 'amount' => 10000, 'direction' => TransactionDirection::Debit, 'merchant_key' => null, 'frequency' => RecurrenceFrequency::EveryMonth, 'start_date' => now()->subMonths(2)->toDateString(), 'until_date' => null]);
    $occurrence = PlannedOccurrence::query()->where('planned_transaction_id', $plan->id)->whereDate('due_date', '<=', now()->toDateString())->orderByDesc('due_date')->firstOrFail();
    $suggestion = AnalysisSuggestion::query()->create([
        'user_id' => $user->id, 'pipeline_run_id' => PipelineRun::factory()->for($user)->create()->id, 'type' => SuggestionType::PlannedPaymentMatch, 'status' => SuggestionStatus::Pending,
        'payload' => ['transaction_id' => $transaction->id, 'planned_transaction_id' => $plan->id, 'planned_occurrence_id' => $occurrence->id, 'account_id' => $account->id, 'score' => $score, 'signals' => ['identity' => 0.35, 'amount' => 0.2, 'date' => 0.05, 'account' => 0.1]],
    ]);

    return [$suggestion, $transaction, $plan, $occurrence];
}

test('the rules page lists suggested payments by confidence and confirms or rejects them', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Everyday']);
    $user->update(['primary_account_id' => $account->id]);
    [$low, $lowTx, $lowPlan] = browserSuggestion($user, $account, 'Water Corp', 0.62);
    [$high, $highTx, $highPlan, $highOccurrence] = browserSuggestion($user, $account, 'Acme Power', 0.80);

    $this->actingAs($user);

    visit('/rules')
        ->assertPresent('[data-testid="payment-suggestions"]')
        ->assertSee('80% confidence')
        ->assertSee('62% confidence')
        ->screenshot(filename: 'planned-payment-review')
        ->click("[data-testid=\"payment-suggestion-{$high->id}\"] [data-testid=\"confirm-payment-suggestion\"]")
        ->wait(2)
        ->assertMissing("[data-testid=\"payment-suggestion-{$high->id}\"]")
        ->click("[data-testid=\"payment-suggestion-{$low->id}\"] [data-testid=\"reject-payment-suggestion\"]")
        ->wait(2)
        ->assertMissing('[data-testid="payment-suggestions"]')
        ->assertNoJavaScriptErrors();

    expect($high->fresh()->status)->toBe(SuggestionStatus::Accepted)
        ->and(PlannedPayment::query()->where('transaction_id', $highTx->id)->count())->toBe(1)
        ->and($highTx->fresh()->planned_transaction_id)->toBe($highPlan->id)
        ->and($highOccurrence->fresh()->status)->toBe(OccurrenceStatus::Paid)
        ->and($low->fresh()->status)->toBe(SuggestionStatus::Rejected)
        ->and(PlannedMatchRejection::query()->where(['planned_transaction_id' => $lowPlan->id, 'transaction_id' => $lowTx->id])->count())->toBe(1);
});
