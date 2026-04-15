<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\AccountClass;
use App\Models\Account;
use App\Models\AnalysisSuggestion;
use Illuminate\View\View;
use Livewire\Component;

final class AccountOverview extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="space-y-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-40"></div>
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-40"></div>
                    <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 min-h-40"></div>
                </div>
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-12"></div>
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
            ->visible()
            ->orderBy('type')
            ->get();

        $totalOwed = $accounts
            ->filter(fn (Account $a) => in_array($a->type, [AccountClass::CreditCard, AccountClass::Loan], true))
            ->sum(fn (Account $a) => $a->amountOwed());

        $totalAvailable = $accounts
            ->filter(fn (Account $a) => $a->type->isSpendable())
            ->sum(fn (Account $a) => $a->availableBalance());
        $buffer = $user->bufferUntilNextPay($totalAvailable);
        $daysUntilPay = $user->daysUntilNextPay();
        $dailySpend = $user->averageDailySpending();
        $projectedSpend = $daysUntilPay !== null ? $dailySpend * $daysUntilPay : null;
        $lastSynced = $accounts->max('updated_at');

        $debtAccountCount = $accounts
            ->filter(fn ($a) => in_array($a->type, [AccountClass::CreditCard, AccountClass::Loan], true))
            ->count();

        $pendingSuggestionCount = AnalysisSuggestion::query()
            ->where('user_id', $user->id)
            ->pending()
            ->count();

        return view('livewire.account-overview', [
            'accounts' => $accounts,
            'totalOwed' => $totalOwed,
            'totalAvailable' => $totalAvailable,
            'buffer' => $buffer,
            'projectedSpend' => $projectedSpend,
            'daysUntilPay' => $daysUntilPay,
            'debtAccountCount' => $debtAccountCount,
            'hasPayCycle' => $user->hasPayCycleConfigured(),
            'lastSynced' => $lastSynced,
            'pendingSuggestionCount' => $pendingSuggestionCount,
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
