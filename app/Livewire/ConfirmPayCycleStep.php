<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Onboarding\ConfirmPayCycle;
use App\Enums\AccountClass;
use App\Enums\PayFrequency;
use App\Enums\PipelineRunStatus;
use App\Enums\RefreshStatus;
use App\Enums\SuggestionType;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use App\Models\PipelineRun;
use App\Models\RedbarkSyncLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Final onboarding step: confirm the primary account and pay cycle the first import
 * detected, or enter them by hand when nothing was detected.
 *
 * The form stays hidden until the first sync and analysis have finished. Confirming
 * earlier would set the primary account and pay cycle before the analysis pipeline's
 * first run, which suppresses the first-import category rule mining.
 *
 * @property-read bool $isAnalysing
 */
final class ConfirmPayCycleStep extends Component
{
    private const int FAILED_SYNC_GRACE_SECONDS = 120;

    private const int ANALYSIS_WAIT_SECONDS = 420;

    public string $primaryAccountId = '';

    public string $payAmount = '';

    public string $payFrequency = '';

    public string $nextPayDate = '';

    public bool $prefilled = false;

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()
            ->where('user_id', Auth::id())
            ->active()
            ->tracked()
            ->whereIn('type', [AccountClass::Transaction, AccountClass::Savings])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function isAnalysing(): bool
    {
        $log = RedbarkSyncLog::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->first();

        if ($log === null) {
            return false;
        }

        if ($log->status === RefreshStatus::Pending) {
            return $log->created_at->greaterThan(now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR));
        }

        $runsSinceSync = PipelineRun::query()
            ->where('user_id', Auth::id())
            ->where('created_at', '>=', $log->updated_at);

        if ((clone $runsSinceSync)->where('status', '!=', PipelineRunStatus::Running)->exists()) {
            return false;
        }

        $analysisDeadline = now()->subSeconds(self::ANALYSIS_WAIT_SECONDS);

        if ($log->status !== RefreshStatus::Failed || $runsSinceSync->exists()) {
            return $log->updated_at->greaterThan($analysisDeadline);
        }

        return $log->created_at->greaterThan(now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR))
            && $log->updated_at->greaterThan(now()->subSeconds(self::FAILED_SYNC_GRACE_SECONDS));
    }

    public function confirm(ConfirmPayCycle $confirmPayCycle): void
    {
        if ($this->isAnalysing) {
            $this->addError('primaryAccountId', __('We are still looking through your transactions. Try again in a moment.'));

            return;
        }

        $accounts = $this->accounts();

        $validated = $this->validate([
            'primaryAccountId' => ['required', Rule::in($accounts->pluck('id')->map(fn (int $id): string => (string) $id)->all())],
            'payAmount' => ['required', 'numeric', 'gt:0'],
            'payFrequency' => ['required', Rule::enum(PayFrequency::class)],
            'nextPayDate' => ['required', 'date', 'after:today'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $account = $accounts->firstWhere('id', (int) $validated['primaryAccountId']);

        try {
            $confirmPayCycle->handle(
                $user,
                $account,
                (int) round((float) $validated['payAmount'] * 100),
                PayFrequency::from($validated['payFrequency']),
                $this->nextPayDate,
            );
        } catch (Throwable $e) {
            report($e);

            $this->addError('primaryAccountId', __('We could not save your pay cycle. Please try again.'));

            return;
        }

        session()->flash('status', __('Primary account and pay cycle saved.'));

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        if (! $this->prefilled && ! $this->isAnalysing) {
            $this->prefill();
        }

        return view('livewire.confirm-pay-cycle-step', [
            'frequencies' => PayFrequency::cases(),
        ]);
    }

    private function prefill(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $suggestions = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->pending()
            ->whereIn('type', [SuggestionType::PrimaryAccount, SuggestionType::PayCycle])
            ->orderBy('id')
            ->get();

        $primary = $suggestions->last(fn (AnalysisSuggestion $s): bool => $s->type === SuggestionType::PrimaryAccount);
        $payCycle = $suggestions->last(fn (AnalysisSuggestion $s): bool => $s->type === SuggestionType::PayCycle);

        $accountId = $user->primary_account_id ?? $primary?->payload['account_id'] ?? null;

        if ($accountId !== null && $this->accounts()->contains('id', (int) $accountId)) {
            $this->primaryAccountId = (string) $accountId;
        }

        if ($user->hasPayCycleConfigured()) {
            $this->payAmount = number_format((int) $user->pay_amount / 100, 2, '.', '');
            $this->payFrequency = $user->pay_frequency->value;
            $this->nextPayDate = $user->next_pay_date->toDateString();
        } elseif ($payCycle !== null) {
            $this->payAmount = number_format((int) $payCycle->payload['pay_amount'] / 100, 2, '.', '');
            $this->payFrequency = (string) $payCycle->payload['pay_frequency'];
            $this->nextPayDate = (string) $payCycle->payload['next_pay_date'];
        }

        $this->prefilled = true;
    }
}
