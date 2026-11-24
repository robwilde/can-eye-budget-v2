<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\UpcomingRecurringItem;
use App\Models\User;
use App\Services\Bills\BillsCalendarService;
use App\Services\Recurring\RecurringGate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The recurring items due in the next ten days, shown above the transactions list, projected from stored
 * occurrences of the user's own plans. Not behind the Bills page flag; it follows the recurring switch.
 *
 * @property-read list<UpcomingRecurringItem> $items
 */
final class UpcomingRecurring extends Component
{
    /** @var array<string, string> */
    protected $listeners = [
        'transaction-saved' => '$refresh',
        'occurrence-payment-changed' => '$refresh',
    ];

    /** @return list<UpcomingRecurringItem> */
    #[Computed]
    public function items(): array
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return RecurringGate::enabled($user) ? app(BillsCalendarService::class)->upcoming($user) : [];
    }

    public function render(): View
    {
        return view('livewire.upcoming-recurring');
    }
}
