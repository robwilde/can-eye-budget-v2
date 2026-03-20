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
        $accounts = auth()->user()
            ->accounts()
            ->active()
            ->orderBy('type')
            ->get();

        $grouped = $accounts->groupBy(fn (Account $account) => $account->type->value);

        $totalAssets = $accounts->filter(fn (Account $a) => $a->type->isAsset())->sum('balance');
        $totalLiabilities = $accounts->filter(fn (Account $a) => ! $a->type->isAsset())->sum('balance');
        $netWorth = $totalAssets + $totalLiabilities;

        return view('livewire.account-overview', [
            'grouped' => $grouped,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'netWorth' => $netWorth,
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
