<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\CashFlowGrouping;
use App\Support\Calendar\CashFlow as CashFlowData;
use App\Support\Calendar\CashFlowLoader;
use App\Support\Dashboard\DashboardPeriod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;

/**
 * Shared by the dashboard's cash-flow and outflows widgets: both read the dashboard period and the
 * user's saved grouping, and either can change the grouping for both. The placeholder has the same root element as the views, so a lazy-loaded widget keeps its event listeners.
 */
trait LoadsDashboardCashFlow
{
    #[Reactive]
    public string $periodStamp = '';

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="animate-pulse rounded-xl bg-zinc-100 min-h-48"></section>
        HTML;
    }

    public function setGrouping(string $grouping): void
    {
        $choice = CashFlowGrouping::tryFrom($grouping);

        if ($choice === null) {
            return;
        }

        auth()->user()->updatePreferences(['cashFlowGrouping' => $choice->value]);

        $this->dispatch('cash-flow-grouping-changed');
    }

    #[On('transaction-saved')]
    public function refreshFigures(): void
    {
        unset($this->cashFlow); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function period(): DashboardPeriod
    {
        return DashboardPeriod::for(auth()->user());
    }

    #[Computed]
    public function grouping(): CashFlowGrouping
    {
        return auth()->user()->preferences->cashFlowGrouping;
    }

    #[Computed]
    public function cashFlow(): CashFlowData
    {
        $period = $this->period; // @phpstan-ignore property.notFound

        return app(CashFlowLoader::class)->load(auth()->user(), $period->start, $period->end, $this->grouping); // @phpstan-ignore property.notFound
    }
}
