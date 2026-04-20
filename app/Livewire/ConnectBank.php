<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Contracts\BasiqServiceContract;
use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\RefreshBasiqConnectionsJob;
use App\Models\BasiqRefreshLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Validate;
use Livewire\Component;

final class ConnectBank extends Component
{
    #[Validate('in:connect,manage')]
    public string $action = 'connect';

    public function mount(): void
    {
        if (auth()->user()->basiq_user_id) {
            $this->action = 'manage';
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function connect(BasiqServiceContract $basiqService): void
    {
        $this->validate();

        $user = auth()->user();

        if (! $user->basiq_user_id) {
            $basiqUser = $basiqService->createUser($user->email);
            $user->update(['basiq_user_id' => $basiqUser->id]);
        }

        $token = $basiqService->clientToken($user->basiq_user_id);

        $state = Str::random(40);
        session()->put('basiq_consent_state', $state);

        $consentUrl = mb_rtrim(config('services.basiq.consent_url'), '/').'/home?'.http_build_query([
            'token' => $token,
            'action' => $this->action,
            'state' => $state,
        ]);

        $this->redirect($consentUrl);
    }

    public function refresh(): void
    {
        $user = auth()->user();

        if (! $user->basiq_user_id) {
            return;
        }

        $hasPendingRefresh = BasiqRefreshLog::query()
            ->where('user_id', $user->id)
            ->where('status', RefreshStatus::Pending)
            ->exists();

        if ($hasPendingRefresh) {
            return;
        }

        $todayRefreshCount = BasiqRefreshLog::query()
            ->where('user_id', $user->id)
            ->whereDate('created_at', today())
            ->count();

        if ($todayRefreshCount >= 20) {
            return;
        }

        $log = BasiqRefreshLog::create([
            'user_id' => $user->id,
            'trigger' => RefreshTrigger::Manual,
            'status' => RefreshStatus::Pending,
        ]);

        RefreshBasiqConnectionsJob::dispatch($user, $log);
    }

    public function render(): View
    {
        $user = auth()->user();
        $isConnected = (bool) $user->basiq_user_id;

        $todayRefreshCount = $isConnected
            ? BasiqRefreshLog::query()
                ->where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count()
            : 0;

        $refreshLogs = $isConnected
            ? BasiqRefreshLog::query()
                ->where('user_id', $user->id)
                ->latest()
                ->limit(10)
                ->get()
            : collect();

        return view('livewire.connect-bank', [
            'isConnected' => $isConnected,
            'accounts' => $isConnected ? $user->accounts()->active()->visible()->get() : collect(),
            'transactionCount' => $isConnected ? $user->transactions()->count() : 0,
            'lastSyncedAt' => $user->last_synced_at,
            'refreshLogs' => $refreshLogs,
            'statusTones' => $refreshLogs->mapWithKeys(
                fn (BasiqRefreshLog $log) => [$log->id => $this->statPillToneFor($log->status)]
            )->all(),
            'todayRefreshCount' => $todayRefreshCount,
            'canRefresh' => $isConnected && $todayRefreshCount < 20,
        ]);
    }

    private function statPillToneFor(RefreshStatus $status): string
    {
        return match ($status) {
            RefreshStatus::Success => 'income',
            RefreshStatus::Pending => 'planned',
            RefreshStatus::Failed => 'posted',
        };
    }
}
