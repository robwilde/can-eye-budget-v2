<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Redbark\SaveRedbarkApiKey;
use App\Enums\RefreshTrigger;
use App\Enums\SuggestionType;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkException;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\AnalysisSuggestion;
use App\Models\GmailCredential;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Post-registration onboarding: connect a Redbark key (step 1), set up the accounts it
 * finds (step 2), then confirm the primary account and pay cycle (step 3). An optional
 * Gmail step follows for users without a mailbox connected. Skippable at every step; the
 * dashboard keeps nudging until a feed exists and a pay cycle is set.
 */
final class ConnectBank extends Component
{
    public const int STEP_CONNECT = 1;

    public const int STEP_ACCOUNTS = 2;

    public const int STEP_PAY_CYCLE = 3;

    public const int STEP_GMAIL = 4;

    public const int TOTAL_STEPS = 3;

    public int $step = self::STEP_CONNECT;

    public string $api_key = '';

    public function mount(): void
    {
        $feed = RedbarkFeed::query()->where('user_id', Auth::id())->first();

        if ($feed === null) {
            return;
        }

        // A feed that has synced and has nothing awaiting setup has finished the account
        // steps. It only counts as finished onboarding once a primary account and pay cycle
        // are set and the suggestions behind them resolved; until then a refresh returns to
        // step 3 rather than past it, including while the analysis runs or found nothing.
        // One that has never synced is still discovering accounts, so it belongs on step 2
        // even though pending_account_setup is only raised once that first sync lands.
        if (! $feed->pending_account_setup && $feed->last_synced_at !== null) {
            if ($this->needsPayCycleConfirmation()) {
                $this->step = self::STEP_PAY_CYCLE;

                return;
            }

            $this->redirect(route('dashboard'), navigate: true);

            return;
        }

        $this->step = self::STEP_ACCOUNTS;
    }

    public function connect(SaveRedbarkApiKey $saveApiKey, RedbarkClientFactory $clients): void
    {
        $validated = $this->validate([
            'api_key' => ['required', 'string', 'min:16', 'max:512'],
        ]);

        try {
            $clients->forValidation($validated['api_key'])->listConnections();
        } catch (RedbarkAuthenticationException $e) {
            $this->addError('api_key', $e->errorType === 'access_forbidden'
                ? __('Redbark denied access with this key. Check that your plan includes API access.')
                : __('Redbark rejected this API key. Check the key and re-enter it.'));

            return;
        } catch (RedbarkException) {
            $this->addError('api_key', __('We could not reach Redbark just now. Please try again in a moment.'));

            return;
        }

        /** @var User $user */
        $user = Auth::user();

        $feed = $saveApiKey->handle($user, $validated['api_key']);

        $this->api_key = '';

        SyncRedbarkFeedJob::dispatchFor($feed, RefreshTrigger::Manual);

        $this->step = self::STEP_ACCOUNTS;
    }

    #[On('accounts-set-up')]
    public function advanceToPayCycle(): void
    {
        $this->step = self::STEP_PAY_CYCLE;
    }

    #[On('pay-cycle-confirmed')]
    public function advanceToGmail(): void
    {
        if (GmailCredential::query()->where('user_id', Auth::id())->exists()) {
            $this->finish();

            return;
        }

        $this->step = self::STEP_GMAIL;
    }

    #[On('gmail-saved')]
    public function finish(): void
    {
        session()->flash('status', __('Primary account and pay cycle saved.'));

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.connect-bank');
    }

    private function needsPayCycleConfirmation(): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->primary_account_id === null
            || ! $user->hasPayCycleConfigured()
            || AnalysisSuggestion::query()
                ->where('user_id', $user->id)
                ->pending()
                ->whereIn('type', [SuggestionType::PrimaryAccount, SuggestionType::PayCycle])
                ->exists();
    }
}
