<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PipelineRunStatus;
use App\Enums\PipelineTrigger;
use App\Enums\SuggestionStatus;
use App\Enums\SuggestionType;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\Category;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Recurring\RecurringSuggestionWriter;
use App\Services\Recurring\RecurringTransactionDetector;
use App\Services\SuggestionApplier;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Throwable;

final class RecurringTransactionReview extends Component
{
    public ?int $accountId = null;

    /** @var array<int, int|string|null> */
    public array $recurringCategories = [];

    public function mount(): void
    {
        $user = $this->authenticatedUser();
        $firstAccountId = $user->accounts()->orderBy('id')->value('id');

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

        if ($categoryId !== null && ! Category::visible()->where('id', $categoryId)->exists()) {
            $categoryId = null;
        }

        $user = $this->authenticatedUser();

        $applier->applyRecurringTransaction($suggestion, $user, $categoryId);

        Flux::toast(text: 'Recurring transaction created', variant: 'success');
    }

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
            'categories' => Category::visibleSortedByFullPath(),
        ]);
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
