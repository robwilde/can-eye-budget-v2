<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Casts\MoneyCast;
use App\Services\Projection\BalanceProjection;
use App\Services\Projection\MonthlyProjectionService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class MonthlyProjection extends Component
{
    public int $months = 12;

    #[Computed]
    public function projection(): ?BalanceProjection
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        return app(MonthlyProjectionService::class)->forUser($user, $this->months);
    }

    #[Computed]
    public function hasPrimaryAccount(): bool
    {
        return $this->projection !== null; // @phpstan-ignore property.notFound
    }

    /**
     * @return array{points: list<array{x: string, y: int}>, firstNegative: ?string, startingBalanceCents: int, hasPrimaryAccount: bool}
     */
    #[Computed]
    public function chartPayload(): array
    {
        $projection = $this->projection; // @phpstan-ignore property.notFound

        if ($projection === null) {
            return [
                'points' => [],
                'firstNegative' => null,
                'startingBalanceCents' => 0,
                'hasPrimaryAccount' => false,
            ];
        }

        $points = [];
        foreach ($projection->points as $point) {
            $points[] = [
                'x' => $point->date->format('Y-m-d'),
                'y' => $point->balanceCents,
            ];
        }

        return [
            'points' => $points,
            'firstNegative' => $projection->firstNegativeDate?->format('Y-m-d'),
            'startingBalanceCents' => $projection->startingBalanceCents,
            'hasPrimaryAccount' => true,
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
