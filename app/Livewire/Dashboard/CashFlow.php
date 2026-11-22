<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\LoadsDashboardCashFlow;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dashboard's cash-flow Sankey over the dashboard period, grouped by category group or account.
 */
#[On('cash-flow-grouping-changed')]
final class CashFlow extends Component
{
    use LoadsDashboardCashFlow;

    public function render(): View
    {
        return view('livewire.dashboard.cash-flow');
    }
}
