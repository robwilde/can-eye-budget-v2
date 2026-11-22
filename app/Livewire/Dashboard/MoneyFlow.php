<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Account;
use App\Services\Reports\DashboardFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Six monthly income and expense bars ending at the selected month, optionally for one account.
 * The month and account live in the URL; a month later than the current one reads as the
 * current month, which is drawn as partial because it only runs to today.
 */
final class MoneyFlow extends Component
{
    #[Url(as: 'flowMonth', except: '')]
    public string $month = '';

    #[Url(as: 'flowAccount', except: '')]
    public string $account = '';

    public function mount(): void
    {
        $this->updatedMonth();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <section class="animate-pulse rounded-xl bg-zinc-100 min-h-48"></section>
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
        unset($this->bars); // @phpstan-ignore property.notFound
    }

    #[Computed]
    public function selectedMonth(): CarbonImmutable
    {
        return app(DashboardFlowService::class)->resolveMonth(auth()->user(), $this->month);
    }

    /**
     * @return list<array{key: string, label: string, income: int, expense: int, partial: bool}>
     */
    #[Computed]
    public function bars(): array
    {
        return app(DashboardFlowService::class)->moneyFlow(auth()->user(), $this->selectedMonth, ctype_digit($this->account) ? (int) $this->account : null); // @phpstan-ignore property.notFound
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return Account::query()->where('user_id', auth()->id())->orderBy('name')->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.dashboard.money-flow', [
            'currentMonth' => auth()->user()->today()->format('Y-m'),
        ]);
    }
}
