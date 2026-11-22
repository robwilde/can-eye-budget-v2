<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\DTOs\BalanceSheet as BalanceSheetData;
use App\Enums\AccountOrder;
use App\Services\NetWorthService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dashboard's balance sheet: the counted accounts as assets and liabilities, grouped by
 * account type and ordered by the user's account-order preference.
 */
final class BalanceSheet extends Component
{
    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="animate-pulse rounded-xl bg-zinc-100 min-h-48"></section>
        HTML;
    }

    public function setAccountOrder(string $order): void
    {
        $accountOrder = AccountOrder::tryFrom($order);

        if ($accountOrder === null) {
            return;
        }

        auth()->user()->updatePreferences(['accountOrder' => $accountOrder->value]);

        unset($this->balanceSheet); // @phpstan-ignore property.notFound
    }

    #[On('transaction-saved')]
    public function refreshFigures(): void
    {
        unset($this->balanceSheet); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function balanceSheet(): BalanceSheetData
    {
        return app(NetWorthService::class)->balanceSheet(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.dashboard.balance-sheet', [
            'orders' => AccountOrder::cases(),
            'selectedOrder' => auth()->user()->preferences->accountOrder,
        ]);
    }
}
