<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\NetWorthService;
use App\Support\Dashboard\DashboardPeriod;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The dashboard's net-worth chart: the signed sum of the user's counted account balances over the
 * dashboard period, drawn at the period's chart granularity, with how far it moved.
 */
final class NetWorthChart extends Component
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
        unset($this->current, $this->series, $this->velocity); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function period(): DashboardPeriod
    {
        return DashboardPeriod::for(auth()->user());
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
        return app(NetWorthService::class)->chartSeries(auth()->user(), $this->period); // @phpstan-ignore property.notFound
    }

    /** How far net worth moved over the period, or null when the balance history does not cover it. */
    #[Computed]
    public function velocity(): ?int
    {
        return app(NetWorthService::class)->velocity(auth()->user(), $this->period); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function favourable(): bool
    {
        return app(NetWorthService::class)->isFavourable($this->series); // @phpstan-ignore property.notFound
    }

    public function render(): View
    {
        return view('livewire.dashboard.net-worth-chart');
    }
}
