<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\OccurrenceStatus;
use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\PlannedTransactionStatus;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Livewire\Attributes\NotAudited;
use App\Livewire\Concerns\RecordsHandPickedCategories;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\PlannedPaymentReview;
use App\Services\Recurring\RecurringSuggestionWriter;
use App\Services\Recurring\RecurringTransactionDetector;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;
use Closure;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

final class RecurringTransactionReview extends Component
{
    use RecordsHandPickedCategories;

    public ?int $accountId = null;

    /** @var array<int, int|string|null> */
    public array $recurringCategories = [];

    public function updatedRecurringCategories(mixed $value, string $key): void
    {
        $this->markHandPicked($key);
    }

    public function mount(): void
    {
        $user = $this->authenticatedUser();
        $firstAccountId = $user->accounts()->tracked()->orderBy('id')->value('id');

        $this->accountId = $user->primary_account_id ?? ($firstAccountId === null ? null : (int) $firstAccountId);
    }

    public function findRecurring(RecurringTransactionDetector $detector, RecurringSuggestionWriter $writer): void
    {
        $user = $this->authenticatedUser();

        if (! $this->selectedAccountBelongsTo($user)) {
            Flux::toast(text: 'Select one of your accounts before scanning', variant: 'warning');

            return;
        }

        $candidates = $detector->detect($user, $this->accountId);

        /** @var list<int> $suggestionIds */
        $suggestionIds = DB::transaction(function () use ($writer, $user, $candidates): array {
            $run = PipelineRun::create([
                'user_id' => $user->id,
                'trigger' => PipelineTrigger::Manual,
                'status' => PipelineRunStatus::Running,
                'started_at' => CarbonImmutable::now(),
            ]);

            AnalysisSuggestion::query()
                ->where('user_id', $user->id)
                ->ofType(SuggestionType::RecurringTransaction)
                ->pending()
                ->where('payload->account_id', $this->accountId)
                ->update([
                    'status' => SuggestionStatus::Superseded,
                    'resolved_at' => CarbonImmutable::now(),
                ]);

            $ids = $writer->writeForRun($user, $run, $candidates);

            $run->update([
                'status' => PipelineRunStatus::Completed,
                'completed_at' => CarbonImmutable::now(),
            ]);

            return $ids;
        });

        $count = count($suggestionIds);
        $message = $count === 1
            ? 'Found 1 recurring pattern'
            : "Found {$count} recurring patterns";

        Flux::toast(text: $message, variant: 'success');
    }

    /**
     * @throws Throwable
     */
    public function accept(int $suggestionId, SuggestionApplier $applier): void
    {
        $suggestion = $this->findPendingSuggestion($suggestionId);

        if (! $suggestion) {
            return;
        }

        $rawCategory = $this->recurringCategories[$suggestionId] ?? $suggestion->payload['category_id'] ?? null;
        $categoryId = $rawCategory !== '' && $rawCategory !== null ? (int) $rawCategory : null;

        if ($categoryId !== null && ! Category::visible()->where('user_id', auth()->id())->where('id', $categoryId)->exists()) {
            $categoryId = null;
        }

        $user = $this->authenticatedUser();

        $applier->applyRecurringTransaction($suggestion, $user, $categoryId);
        $this->recordHandPicked($user, $suggestionId, $categoryId);

        Flux::toast(text: $categoryId !== null ? 'Recurring transaction created and all matching transactions categorised' : 'Recurring transaction created', variant: 'success');
    }

    public function confirmPlannedPayment(int $suggestionId, PlannedPaymentReview $review): void
    {
        $this->decidePlannedPayment($suggestionId, fn (User $user) => $review->confirm($user, $suggestionId), 'Payment applied');
    }

    public function rejectPlannedPayment(int $suggestionId, PlannedPaymentReview $review): void
    {
        $this->decidePlannedPayment($suggestionId, fn (User $user) => $review->reject($user, $suggestionId), 'Match rejected. It will not be suggested again.');
    }

    #[NotAudited]
    #[On('occurrence-payment-changed')]
    public function refreshAfterPayment(): void {}

    public function dismiss(int $suggestionId): void
    {
        $suggestion = $this->findPendingSuggestion($suggestionId);

        if (! $suggestion) {
            return;
        }

        $suggestion->update([
            'status' => SuggestionStatus::Rejected,
            'resolved_at' => CarbonImmutable::now(),
        ]);

        Flux::toast(text: 'Recurring transaction dismissed', variant: 'warning');
    }

    public function render(): View
    {
        $accounts = $this->accountsForUser();
        $suggestions = $this->pendingSuggestionsForSelectedAccount();

        $this->recurringCategories = array_intersect_key(
            $this->recurringCategories,
            $suggestions->pluck('id')->flip()->all(),
        );

        foreach ($suggestions as $suggestion) {
            if (! array_key_exists($suggestion->id, $this->recurringCategories)) {
                $this->recurringCategories[$suggestion->id] = $suggestion->payload['category_id'] ?? null;
            }
        }

        return view('livewire.recurring-transaction-review', [
            'accounts' => $accounts,
            'suggestions' => $suggestions,
            'paymentSuggestions' => $this->paymentSuggestionsForSelectedAccount(),
            'categories' => Category::visibleSortedByFullPath($this->authenticatedUser()->id),
        ]);
    }

    private function decidePlannedPayment(int $suggestionId, Closure $decide, string $toast): void
    {
        $this->resetErrorBag('plannedPayment');

        if ($this->settledSuggestion($suggestionId)) {
            $this->addError('plannedPayment', __('This suggestion changed in the meantime. The list has been refreshed.'));

            return;
        }

        try {
            $decide($this->authenticatedUser());
        } catch (ValidationException $refused) {
            $this->addError('plannedPayment', (string) collect($refused->errors())->flatten()->first());

            return;
        } catch (ModelNotFoundException) {
            abort(404);
        }

        Flux::toast(text: $toast, variant: 'success');
        $this->dispatch('occurrence-payment-changed');
    }

    private function settledSuggestion(int $suggestionId): bool
    {
        return AnalysisSuggestion::query()
            ->where('user_id', auth()->id())
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->whereKey($suggestionId)
            ->where('status', '!=', SuggestionStatus::Pending)
            ->exists();
    }

    /**
     * Pending suggested plan payments of the selected account, most confident first, each with the plan,
     * transaction and occurrence it names. One whose records are gone is left out.
     *
     * @return Collection<int, array{suggestion: AnalysisSuggestion, plan: PlannedTransaction, transaction: Transaction, occurrence: PlannedOccurrence}>
     */
    private function paymentSuggestionsForSelectedAccount(): Collection
    {
        if ($this->accountId === null) {
            return collect();
        }

        $user = $this->authenticatedUser();
        $suggestions = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->ofType(SuggestionType::PlannedPaymentMatch)
            ->pending()
            ->where('payload->account_id', $this->accountId)
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

    private function authenticatedUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function selectedAccountBelongsTo(User $user): bool
    {
        if ($this->accountId === null) {
            return false;
        }

        return Account::query()
            ->where('user_id', $user->id)
            ->tracked()
            ->whereKey($this->accountId)
            ->exists();
    }

    private function findPendingSuggestion(int $suggestionId): ?AnalysisSuggestion
    {
        $suggestion = AnalysisSuggestion::query()
            ->where('user_id', auth()->id())
            ->ofType(SuggestionType::RecurringTransaction)
            ->pending()
            ->find($suggestionId);

        return $suggestion instanceof AnalysisSuggestion ? $suggestion : null;
    }

    /** @return Collection<int, Account> */
    private function accountsForUser(): Collection
    {
        return Account::query()
            ->where('user_id', auth()->id())
            ->tracked()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, AnalysisSuggestion> */
    private function pendingSuggestionsForSelectedAccount(): Collection
    {
        if ($this->accountId === null) {
            return collect();
        }

        return AnalysisSuggestion::query()
            ->where('user_id', auth()->id())
            ->ofType(SuggestionType::RecurringTransaction)
            ->pending()
            ->where('payload->account_id', $this->accountId)
            ->latest()
            ->get();
    }
}
