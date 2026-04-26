<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Casts\MoneyCast;
use App\Services\Projection\MonthlyProjectionService;
use App\Services\Projection\ProjectedMonth;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class MonthlyProjection extends Component
{
    public int $months = 12;

    /**
     * @return Collection<int, ProjectedMonth>
     */
    #[Computed]
    public function projection(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            /** @var Collection<int, ProjectedMonth> */
            return collect();
        }

        return app(MonthlyProjectionService::class)->forUser($user, $this->months);
    }

    #[Computed]
    public function firstRiskyMonth(): ?ProjectedMonth
    {
        return $this->projection->first(static fn (ProjectedMonth $month) => $month->isRisky()); // @phpstan-ignore property.notFound
    }

    /**
     * @return array{categories: list<string>, income: list<int>, expense: list<int>, cumulative: list<int>, riskyIndexes: list<int>, oneOffs: list<array{x: string, label: string, amountCents: int}>}
     */
    #[Computed]
    public function chartPayload(): array
    {
        $categories = [];
        $income = [];
        $expense = [];
        $cumulative = [];
        $riskyIndexes = [];
        $oneOffs = [];

        foreach ($this->projection as $month) { // @phpstan-ignore property.notFound
            $label = $month->isYearStart && $month->monthIndex !== 0
                ? sprintf('%s %d', $month->label, $month->year)
                : $month->label;

            $categories[] = $label;
            $income[] = $month->incomeCents;
            $expense[] = $month->expenseCents;
            $cumulative[] = $month->cumulativeNetCents;

            if ($month->isRisky()) {
                $riskyIndexes[] = $month->monthIndex;
            }

            foreach ($month->oneOffs as $oneOff) {
                $oneOffs[] = [
                    'x' => $label,
                    'label' => $oneOff->description,
                    'amountCents' => $oneOff->amountCents,
                ];
            }
        }

        return [
            'categories' => $categories,
            'income' => $income,
            'expense' => $expense,
            'cumulative' => $cumulative,
            'riskyIndexes' => $riskyIndexes,
            'oneOffs' => $oneOffs,
        ];
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <section class="monthly-projection">
                <div class="animate-pulse h-6 w-56 rounded bg-(--color-cib-n-100)"></div>
                <div class="animate-pulse h-64 mt-4 rounded bg-(--color-cib-n-100)"></div>
            </section>
            HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.monthly-projection', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }
}
