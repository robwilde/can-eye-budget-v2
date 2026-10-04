<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Redbark\SaveRedbarkApiKey;
use App\Enums\RefreshTrigger;
use App\Exceptions\Redbark\RedbarkAuthenticationException;
use App\Exceptions\Redbark\RedbarkException;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Services\RedbarkClientFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Post-registration onboarding: connect a Redbark key (step 1), then set up the
 * accounts it finds (step 2). Skippable at every step; the dashboard keeps nudging
 * until a feed exists.
 */
final class ConnectBank extends Component
{
    public const int STEP_CONNECT = 1;

    public const int STEP_ACCOUNTS = 2;

    public int $step = self::STEP_CONNECT;

    public string $api_key = '';

    public function mount(): void
    {
        $feed = RedbarkFeed::query()->where('user_id', Auth::id())->first();

        if ($feed === null) {
            return;
        }

        // A feed that has synced and has nothing awaiting setup is finished onboarding.
        // One that has never synced is still discovering accounts, so it belongs on step 2
        // even though pending_account_setup is only raised once that first sync lands.
        if (! $feed->pending_account_setup && $feed->last_synced_at !== null) {
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

    public function render(): View
    {
        return view('livewire.connect-bank');
    }
}
