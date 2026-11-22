<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Category;
use App\Services\Reports\DashboardFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cumulative daily spending for the selected month against the month before, optionally for one
 * category and its children. The month and category live in the URL.
 */
final class SpendingTrend extends Component
{
    #[Url(as: 'trendMonth')]
    public string $month = '';

    #[Url(as: 'trendCategory')]
    public string $category = '';

    public function mount(): void
    {
        $this->updatedMonth();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="animate-pulse rounded-xl bg-zinc-100 min-h-48"></div>
        HTML;
    }

    public function updatedMonth(): void
    {
        $user = auth()->user();
        $resolved = app(DashboardFlowService::class)->resolveMonth($user, $this->month);

        $this->month = $resolved->isSameMonth($user->today()) ? '' : $resolved->format('Y-m');
    }

    #[On('transaction-saved')]
    public function refreshFigures(): void
    {
        unset($this->trend); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function selectedMonth(): CarbonImmutable
    {
        return app(DashboardFlowService::class)->resolveMonth(auth()->user(), $this->month);
    }

    /**
     * @return array{days: int, elapsed: int, current: list<int>, previous: list<int>, currentTotal: int, previousTotal: int, delta: int}
     */
    #[Computed]
    public function trend(): array
    {
        return app(DashboardFlowService::class)->spendingTrend(
            auth()->user(),
            $this->selectedMonth, // @phpstan-ignore property.notFound
            ctype_digit($this->category) ? (int) $this->category : null,
        );
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        $byParent = Category::query()->where('user_id', auth()->id())->orderBy('name')->get(['id', 'name', 'parent_id'])
            ->groupBy(static fn (Category $category): int => $category->parent_id ?? 0);
        $ordered = new Collection;
        $walk = static function (int $parent) use (&$walk, $byParent, $ordered): void {
            foreach ($byParent->get($parent, []) as $category) {
                $ordered->push($category);
                $walk($category->id);
            }
        };
        $walk(0);

        return $ordered;
    }

    public function render(): View
    {
        return view('livewire.dashboard.spending-trend', [
            'currentMonth' => auth()->user()->today()->format('Y-m'),
        ]);
    }
}
