<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\BnplOrderStatus;
use App\Models\BnplOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

final class BnplPendingBadge extends Component
{
    /** @var array<string, string> */
    protected $listeners = ['bnpl-orders-reviewed' => '$refresh'];

    #[Computed]
    public function pendingCount(): int
    {
        if (! config('budget.bnpl_email_import') || Auth::id() === null) {
            return 0;
        }

        return BnplOrder::query()
            ->where('user_id', Auth::id())
            ->where('status', BnplOrderStatus::PendingReview)
            ->count();
    }

    public function render(): View
    {
        return view('livewire.bnpl-pending-badge');
    }
}
