<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\Investments\InvestmentPortfolio;
use App\Support\Dashboard\DashboardPeriod;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The dashboard's investment summary: the value of the user's active investment accounts, the price
 * return of what they hold over the dashboard period, the day's change and the largest holdings. The
 * dashboard shows it only while the investments flag is on and an investment account holds a position.
 */
final class InvestmentSummary extends Component
{
    #[Reactive]
    public string $periodStamp = '';

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="animate-pulse rounded-xl bg-zinc-100 min-h-48"></section>
        HTML;
    }

    #[On('transaction-saved')]
    public function refreshFigures(): void
    {
        unset($this->overview); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function period(): DashboardPeriod
    {
        return DashboardPeriod::for(auth()->user());
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function overview(): array
    {
        $user = auth()->user();
        $portfolio = app(InvestmentPortfolio::class);

        $period = $this->period; // @phpstan-ignore property.notFound

        return $portfolio->overview($user, $portfolio->accounts($user), $period->start, $period->end);
    }

    public function render(): View
    {
        return view('livewire.dashboard.investment-summary');
    }
}
