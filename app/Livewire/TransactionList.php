<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Casts\MoneyCast;
use App\Enums\TransactionDirection;
use App\Enums\TransactionPeriod;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionList extends Component
{
    use WithPagination;

    private const array SORTABLE_COLUMNS = ['post_date', 'amount', 'description'];

    private const array VALID_DIRECTIONS = ['all', 'incoming', 'outgoing'];

    private const array VALID_PLANNED_FILTERS = ['all', 'planned', 'unplanned'];

    private const array VALID_CATEGORISED_FILTERS = ['all', 'categorised', 'uncategorised'];

    #[Url]
    public string $direction = 'all';

    #[Url]
    public string $planned = 'all';

    #[Url]
    public string $categorised = 'all';

    #[Url]
    public ?int $account = null;

    #[Url]
    public ?int $category = null;

    #[Url]
    public string $period = 'this-month';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    #[Url(except: '')]
    public string $search = '';

    #[Url]
    public string $sortBy = 'post_date';

    #[Url]
    public string $sortDir = 'desc';

    public function mount(): void
    {
        $this->restoreRememberedPeriod();

        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }

        if (! in_array($this->planned, self::VALID_PLANNED_FILTERS, true)) {
            $this->planned = 'all';
        }

        if (! in_array($this->categorised, self::VALID_CATEGORISED_FILTERS, true)) {
            $this->categorised = 'all';
        }

        $this->period = match ($this->period) {
            '30d' => 'this-month',
            '90d' => '3m',
            '12m' => '1y',
            default => $this->period,
        };

        if (! TransactionPeriod::tryFrom($this->period)) {
            $this->period = 'this-month';
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

    /**
     * The modal saved or deleted a transaction/plan; re-render so the fresh
     * render() query picks up the change.
     */
    #[On('transaction-saved')]
    public function refreshList(): void {}

    public function updatedDirection(): void
    {
        if (! in_array($this->direction, self::VALID_DIRECTIONS, true)) {
            $this->direction = 'all';
        }

        $this->resetPage();
    }

    public function updatedPlanned(): void
    {
        if (! in_array($this->planned, self::VALID_PLANNED_FILTERS, true)) {
            $this->planned = 'all';
        }

        $this->resetPage();
    }

    public function updatedCategorised(): void
    {
        if (! in_array($this->categorised, self::VALID_CATEGORISED_FILTERS, true)) {
            $this->categorised = 'all';
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
        session()->put('transactions.period', $this->period);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
        session()->put('transactions.from', $this->from);
    }

    public function updatedTo(): void
    {
        $this->resetPage();
        session()->put('transactions.to', $this->to);
    }

    public function render(): View
    {
        $periodEnum = TransactionPeriod::tryFrom($this->period) ?? TransactionPeriod::ThisMonth;
        $dates = $periodEnum->dateRange(auth()->user(), $this->from, $this->to);

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
            ->when($this->planned === 'planned', fn ($q) => $q->whereNotNull('planned_transaction_id'))
            ->when($this->planned === 'unplanned', fn ($q) => $q->whereNull('planned_transaction_id'))
            ->when($this->categorised === 'categorised', fn ($q) => $q->whereNotNull('category_id'))
            ->when($this->categorised === 'uncategorised', fn ($q) => $q->whereNull('category_id'))
            ->when($this->search, fn ($q, $term) => $q->where(function ($q) use ($term) {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('clean_description', 'like', "%{$term}%")
                    ->orWhere('merchant_name', 'like', "%{$term}%");
            }))
            ->when($dates['start'], fn ($q, $s) => $q->where('post_date', '>=', $s))
            ->when($dates['end'], fn ($q, $e) => $q->where('post_date', '<=', $e))
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

        $grouped = $transactions->getCollection()
            ->groupBy(static fn (Transaction $t): string => $t->post_date->format('Y-m-d'));

        return view('livewire.transaction-list', [
            'transactions' => $transactions,
            'grouped' => $grouped,
            'accounts' => $accounts,
            'categoryName' => $this->category
                ? Category::query()->whereKey($this->category)->value('name')
                : null,
            'formatMoney' => MoneyCast::format(...),
            'periodLabel' => $periodEnum->label(),
            'hasPayCycle' => auth()->user()->hasPayCycleConfigured(),
            'showCustomRange' => $periodEnum === TransactionPeriod::Custom,
        ]);
    }

    private function restoreRememberedPeriod(): void
    {
        if (request()->query('period') !== null) {
            return;
        }

        $period = session()->get('transactions.period');

        if (! is_string($period)) {
            return;
        }

        $this->period = $period;

        if (request()->query('from') === null) {
            $from = session()->get('transactions.from');
            $this->from = is_string($from) ? $from : null;
        }

        if (request()->query('to') === null) {
            $to = session()->get('transactions.to');
            $this->to = is_string($to) ? $to : null;
        }
    }
}
