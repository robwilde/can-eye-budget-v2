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
 * The reports page's investment performance section. A child of the page, like the other sections, so
 * it re-renders only when the report range changes.
 */
final class InvestmentPerformanceSection extends Component
{
    #[Reactive]
    public string $from = '';

    #[Reactive]
    public string $to = '';

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return app(InvestmentReport::class)->performance(auth()->user(), CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
    }

    public function render(): View
    {
        return view('livewire.reports.investment-performance-section');
    }
}
