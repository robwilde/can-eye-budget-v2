<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reports\ReportFilters;
use App\Services\Reports\TrendsReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The reports page's trends section. A child of the page so that actions on the page that do not
 * change its range or filters do not rebuild it.
 */
final class TrendsSection extends Component
{
    #[Reactive]
    public string $from = '';

    #[Reactive]
    public string $to = '';

    #[Reactive]
    public ?int $accountId = null;

    #[Reactive]
    public ?int $tagId = null;

    #[Reactive]
    public ?int $minCents = null;

    #[Reactive]
    public ?int $maxCents = null;

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        $filters = new ReportFilters($this->accountId, $this->tagId, $this->minCents, $this->maxCents);

        return app(TrendsReport::class)->build(auth()->user(), CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to), $filters->isEmpty() ? null : $filters);
    }

    public function render(): View
    {
        return view('livewire.reports.trends-section');
    }
}
