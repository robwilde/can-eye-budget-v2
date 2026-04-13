<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PayFrequency;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Services\RuleActionExecutor;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

final class AnalysisSuggestions extends Component
{
    public string $payAmount = '';

    public string $payFrequency = '';

    public string $nextPayDate = '';

    public bool $payCycleFieldsLoaded = false;

    /** @var array<int, int|string|null> */
    public array $recurringCategories = [];

    public function acceptPrimaryAccount(int $suggestionId): void
    {
        $suggestion = $this->findPendingSuggestion($suggestionId, SuggestionType::PrimaryAccount);

        if (! $suggestion) {
            return;
        }

        auth()->user()->update(['primary_account_id' => $suggestion->payload['account_id']]);
        $this->resolveSuggestion($suggestion, SuggestionStatus::Accepted);

        Flux::toast(text: 'Primary account set', variant: 'success');
    }

    public function acceptPayCycle(int $suggestionId): void
    {
        $this->validate([
            'payAmount' => ['required', 'numeric', 'gt:0'],
            'payFrequency' => ['required', 'in:'.implode(',', array_column(PayFrequency::cases(), 'value'))],
            'nextPayDate' => ['required', 'date', 'after:today'],
        ]);

        $suggestion = $this->findPendingSuggestion($suggestionId, SuggestionType::PayCycle);

        if (! $suggestion) {
            return;
        }

        $cents = (int) round((float) $this->payAmount * 100);

        auth()->user()->update([
            'pay_amount' => $cents,
            'pay_frequency' => $this->payFrequency,
            'next_pay_date' => $this->nextPayDate,
        ]);

        $this->resolveSuggestion($suggestion, SuggestionStatus::Accepted);

        Flux::toast(text: 'Pay cycle configured', variant: 'success');
    }

    /**
     * @throws Throwable
     */
    public function acceptRecurringTransaction(int $suggestionId): void
    {
        $suggestion = $this->findPendingSuggestion($suggestionId, SuggestionType::RecurringTransaction);

        if (! $suggestion) {
            return;
        }

        $payload = $suggestion->payload;
        $user = auth()->user();
        $rawCategory = $this->recurringCategories[$suggestionId] ?? $payload['category_id'] ?? null;
        $categoryId = $rawCategory !== '' && $rawCategory !== null ? (int) $rawCategory : null;

        if ($categoryId !== null && ! Category::visible()->where('id', $categoryId)->exists()) {
            $categoryId = null;
        }

        DB::transaction(function () use ($suggestion, $payload, $user, $categoryId): void {
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

            $this->resolveSuggestion($suggestion, SuggestionStatus::Accepted);
        });

        Flux::toast(text: 'Recurring transaction created', variant: 'success');
    }

    public function acceptUserRule(int $suggestionId, RuleActionExecutor $executor): void
    {
        $suggestion = $this->findPendingSuggestion($suggestionId, SuggestionType::UserRule);

        if (! $suggestion) {
            return;
        }

        $transaction = Transaction::findCurrentVersion(
            (int) $suggestion->payload['transaction_id'],
            (int) auth()->id(),
        );

        if (! $transaction) {
            return;
        }

        $executor->execute($transaction, $suggestion->payload['actions']);
        $this->resolveSuggestion($suggestion, SuggestionStatus::Accepted);

        Flux::toast(text: 'Rule applied to transaction', variant: 'success');
    }

    public function rejectSuggestion(int $suggestionId): void
    {
        $suggestion = AnalysisSuggestion::find($suggestionId);

        if (! $suggestion || $suggestion->user_id !== auth()->id()) {
            return;
        }

        if ($suggestion->status !== SuggestionStatus::Pending) {
            return;
        }

        $this->resolveSuggestion($suggestion, SuggestionStatus::Rejected);

        Flux::toast(text: 'Suggestion dismissed', variant: 'warning');
    }

    public function render(): View
    {
        $suggestions = AnalysisSuggestion::query()
            ->where('user_id', auth()->id())
            ->pending()
            ->get()
            ->groupBy(fn (AnalysisSuggestion $s) => $s->type->value);

        if (! $this->payCycleFieldsLoaded) {
            $payCycleSuggestions = $suggestions->get(SuggestionType::PayCycle->value);

            if ($payCycleSuggestions?->isNotEmpty()) {
                $payload = $payCycleSuggestions->first()->payload;
                $this->payAmount = number_format($payload['pay_amount'] / 100, 2, '.', '');
                $this->payFrequency = $payload['pay_frequency'];
                $this->nextPayDate = $payload['next_pay_date'];
                $this->payCycleFieldsLoaded = true;
            }
        }

        $recurringTransactions = $suggestions->get(SuggestionType::RecurringTransaction->value);

        if ($recurringTransactions) {
            foreach ($recurringTransactions as $suggestion) {
                if (! array_key_exists($suggestion->id, $this->recurringCategories)) {
                    $this->recurringCategories[$suggestion->id] = $suggestion->payload['category_id'] ?? null;
                }
            }
        }

        return view('livewire.analysis-suggestions', [
            'suggestions' => $suggestions,
            'categories' => Category::visible()->with(['parent.parent'])->orderBy('name')->get(),
        ]);
    }

    private function findPendingSuggestion(int $suggestionId, SuggestionType $type): ?AnalysisSuggestion
    {
        $suggestion = AnalysisSuggestion::find($suggestionId);

        if (! $suggestion || $suggestion->user_id !== auth()->id()) {
            return null;
        }

        if ($suggestion->status !== SuggestionStatus::Pending) {
            return null;
        }

        if ($suggestion->type !== $type) {
            return null;
        }

        return $suggestion;
    }

    private function resolveSuggestion(AnalysisSuggestion $suggestion, SuggestionStatus $status): void
    {
        $suggestion->update([
            'status' => $status,
            'resolved_at' => now(),
        ]);
    }
}
