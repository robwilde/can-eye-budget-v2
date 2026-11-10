<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\DTOs\BalanceSheet;
use App\Enums\AccountOrder;
use App\Services\NetWorthService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

final class NetWorth extends Component
{
    private const array PERIOD_DAYS = [30, 90, 365];

    public int $days = 90;

    public function updatedDays(): void
    {
        if (! in_array($this->days, self::PERIOD_DAYS, true)) {
            $this->days = 90;
        }
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
        unset($this->current, $this->series, $this->balanceSheet); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function current(): int
    {
        return app(NetWorthService::class)->current(auth()->user());
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function series(): array
    {
        $user = auth()->user();
        $today = $user->today();

        return app(NetWorthService::class)->series($user, $today->subDays($this->days - 1), $today);
    }

    #[Computed]
    public function favourable(): bool
    {
        return app(NetWorthService::class)->isFavourable($this->series); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function balanceSheet(): BalanceSheet
    {
        return app(NetWorthService::class)->balanceSheet(auth()->user());
    }

    public function render(): View
    {
        return view('livewire.dashboard.net-worth', [
            'periods' => self::PERIOD_DAYS,
            'orders' => AccountOrder::cases(),
            'selectedOrder' => auth()->user()->preferences->accountOrder,
        ]);
    }
}
