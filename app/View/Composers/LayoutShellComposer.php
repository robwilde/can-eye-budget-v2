<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\View\View;

final class LayoutShellComposer
{
    private ?User $user;

    private bool $resolved = false;

    private ?BasiqRefreshLog $latestSync = null;

    private int $accountCount = 0;

    private ?int $daysUntilNextPay = null;

    public function compose(View $view): void
    {
        $this->resolveOnce();

        $view->with([
            'shellUser' => $this->user,
            'shellAccountCount' => $this->accountCount,
            'shellLatestSync' => $this->latestSync,
            'shellSyncedHuman' => $this->latestSync?->created_at->diffForHumans(short: true) ?? '—',
            'shellDaysUntilNextPay' => $this->daysUntilNextPay,
        ]);
    }

    private function resolveOnce(): void
    {
        if ($this->resolved) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();
        $this->user = $user;

        if ($user !== null) {
            $this->accountCount = $user->accounts()->active()->count();
            $this->daysUntilNextPay = $user->daysUntilNextPay();
            $this->latestSync = BasiqRefreshLog::query()
                ->where('user_id', $user->id)
                ->latest()
                ->first();
        }

        $this->resolved = true;
    }
}
