<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Models\Account;
use Illuminate\View\View;
use Livewire\Component;

final class AccountOverview extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="space-y-4">
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-24"></div>
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-48"></div>
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-48"></div>
            </div>
        </div>
        HTML;
    }

    public function render(): View
    {
        $user = auth()->user();

        $accounts = $user
            ->accounts()
            ->active()
            ->orderBy('type')
            ->get();

        $availableToSpend = $accounts
            ->filter(fn (Account $a) => $a->type->isSpendable())
            ->sum(fn (Account $a) => $a->availableBalance());
        $buffer = $user->bufferUntilNextPay($availableToSpend);
        $lastSynced = $accounts->max('updated_at');

        return view('livewire.account-overview', [
            'accounts' => $accounts,
            'availableToSpend' => $availableToSpend,
            'buffer' => $buffer,
            'hasPayCycle' => $user->hasPayCycleConfigured(),
            'lastSynced' => $lastSynced,
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
