<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class SpendingByCategory extends Component
{
    private const array FALLBACK_COLORS = [
        '#6366F1', '#8B5CF6', '#EC4899', '#F43F5E',
        '#F97316', '#EAB308', '#22C55E', '#14B8A6',
        '#06B6D4', '#3B82F6', '#A855F7', '#78716C',
    ];

    public string $period = '30d';

    /** @return array<int, array{name: string, total: int, color: string, category_id: int|null}> */
    #[Computed(persist: true)]
    public function categoryData(): array
    {
        $rows = Transaction::query()
            ->where('user_id', auth()->id())
            ->where('direction', TransactionDirection::Debit)
            ->where('post_date', '>=', $this->periodStart())
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->with('category:id,name,color')
            ->orderByDesc('total')
            ->get();

        $index = 0;

        return $rows->map(function ($row) use (&$index) {
            $category = $row->category;
            $color = $category?->color ?? self::FALLBACK_COLORS[$index % count(self::FALLBACK_COLORS)]; // @phpstan-ignore nullsafe.neverNull
            $index++;

            return [
                'name' => $category?->name ?? 'Uncategorized', // @phpstan-ignore nullsafe.neverNull
                'total' => (int) $row->total, // @phpstan-ignore property.notFound
                'color' => $color,
                'category_id' => $row->category_id,
            ];
        })->all();
    }

    public function updatedPeriod(): void
    {
        unset($this->categoryData); // @phpstan-ignore property.notFound
        $this->dispatch('chart-updated', data: $this->categoryData); // @phpstan-ignore property.notFound
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div>
            <div class="space-y-4">
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-8 w-32"></div>
                <div class="animate-pulse rounded-xl bg-zinc-100 dark:bg-zinc-800 h-64"></div>
            </div>
        </div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.spending-by-category', [
            'formatMoney' => MoneyCast::format(...),
        ]);
    }

    private function periodStart(): CarbonInterface
    {
        return match ($this->period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '12m' => now()->subMonths(12),
            default => now()->subDays(30),
        };
    }
}
