<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Models\Account;
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

    private const array SORTABLE_COLUMNS = ['post_date', 'amount', 'description'];

    private const array VALID_DIRECTIONS = ['all', 'incoming', 'outgoing'];

    #[Url]
    public string $direction = 'all';

    #[Url]
    public ?int $account = null;

    #[Url]
    public ?int $category = null;

    #[Url]
    public string $period = '30d';

    #[Url(except: '')]
    public string $search = '';

    #[Url]
    public string $sortBy = 'post_date';

    #[Url]
    public string $sortDir = 'desc';

    public function mount(): void
    {
        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function updatedDirection(): void
    {
        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }

        $this->resetPage();
    }

    public function updatedAccount(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $directionEnum = match ($this->direction) {
            'incoming' => TransactionDirection::Credit,
            'outgoing' => TransactionDirection::Debit,
            default => null,
        };

        $transactions = Transaction::query()
            ->where('user_id', auth()->id())
            ->current()
            ->when($directionEnum, fn ($q, $dir) => $q->where('direction', $dir))
            ->when($this->account, fn ($q, $id) => $q->where('account_id', $id))
            ->when($this->category, fn ($q, $id) => $q->where('category_id', $id))
            ->when($this->search, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('clean_description', 'like', "%{$term}%")
                    ->orWhere('merchant_name', 'like', "%{$term}%");
            }))
            ->where('post_date', '>=', $this->periodStart())
            ->withRelations()
            ->orderBy(
                in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'post_date',
                in_array($this->sortDir, ['asc', 'desc'], true) ? $this->sortDir : 'desc',
            )
            ->paginate(25);

        $accounts = Account::query()
            ->where('user_id', auth()->id())
            ->active()
            ->get(['id', 'name']);

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'accounts' => $accounts,
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
