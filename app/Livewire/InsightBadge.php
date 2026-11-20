<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\InsightStatus;
use App\Models\Insight;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class InsightBadge extends Component
{
    public const int CAP = 9;

    #[Computed]
    public function unreadCount(): int
    {
        if (config('budget.insights_enabled') !== true || Auth::id() === null) {
            return 0;
        }

        return Insight::query()
            ->where('user_id', Auth::id())
            ->where('status', InsightStatus::Active->value)
            ->count();
    }

    public function render(): View
    {
        return view('livewire.insight-badge');
    }
}
