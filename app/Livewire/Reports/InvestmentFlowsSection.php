<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reports\InvestmentReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The reports page's investment flows section: what was paid into and out of the investment accounts
 * in the report range. The page only shows it when the range has some.
 */
final class InvestmentFlowsSection extends Component
{
    #[Reactive]
    public string $from = '';

    #[Reactive]
    public string $to = '';

    /**
     * @return array{contributions: int, withdrawals: int, net: int}
     */
    #[Computed]
    public function flows(): array
    {
        return app(InvestmentReport::class)->flows(auth()->user(), CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
    }

    public function render(): View
    {
        return view('livewire.reports.investment-flows-section');
    }
}
