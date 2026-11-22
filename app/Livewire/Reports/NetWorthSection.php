<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Services\Reports\NetWorthReport;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The reports page's net-worth section. A child of the page so that actions on the page that do not
 * change its range, such as paging or expanding a category, do not rebuild it: it re-renders only when
 * one of its reactive props changes.
 */
final class NetWorthSection extends Component
{
    #[Reactive]
    public string $from = '';

    #[Reactive]
    public string $to = '';

    #[Reactive]
    public bool $allTime = false;

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return app(NetWorthReport::class)->build(auth()->user(), CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to), $this->allTime);
    }

    public function render(): View
    {
        return view('livewire.reports.net-worth-section');
    }
}
