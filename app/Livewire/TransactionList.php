<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionList extends Component
{
    use WithPagination;

    #[Url]
    public ?int $category = null;

    #[Url]
    public string $period = '30d';

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $transactions = Transaction::query()
            ->where('user_id', auth()->id())
            ->where('direction', TransactionDirection::Debit)
            ->when($this->category, fn ($q, $id) => $q->where('category_id', $id))
            ->where('post_date', '>=', $this->periodStart())
            ->with('category')
            ->latest('post_date')
            ->paginate(25);

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'categoryName' => $this->category
                ? Category::query()->whereKey($this->category)->value('name')
                : null,
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
