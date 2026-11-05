<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\LoanSchedule;
use App\Models\Account;
use App\Services\LoanAmortisationService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Read-only page for one account: its details and, for loans and mortgages, the repayment schedule.
 *
 * @property-read LoanSchedule|null $schedule
 */
#[Layout('layouts::app')]
final class AccountShow extends Component
{
    private const string TAB_OVERVIEW = 'overview';

    private const string TAB_SCHEDULE = 'schedule';

    #[Locked]
    public Account $account;

    #[Url]
    public string $tab = self::TAB_OVERVIEW;

    public function mount(Account $account): void
    {
        abort_unless($account->user_id === auth()->id(), 404);

        $this->account = $account;
        $this->normaliseTab();
    }

    public function updatedTab(): void
    {
        $this->normaliseTab();
    }

    #[Computed]
    public function schedule(): ?LoanSchedule
    {
        $detail = $this->account->detail;

        if (! $this->account->type->amortises()
            || $detail === null
            || $detail->original_principal === null
            || $detail->original_principal <= 0
            || $detail->rate === null
            || $detail->term_months === null
            || $detail->start_date === null) {
            return null;
        }

        return app(LoanAmortisationService::class)->schedule(
            $detail->original_principal,
            (float) $detail->rate,
            $detail->term_months,
            $detail->start_date,
        );
    }

    public function render(): View
    {
        $tabs = [['value' => self::TAB_OVERVIEW, 'label' => __('Overview')]];

        if ($this->account->type->amortises()) {
            $tabs[] = ['value' => self::TAB_SCHEDULE, 'label' => __('Schedule')];
        }

        return view('livewire.account-show', ['tabs' => $tabs]);
    }

    private function normaliseTab(): void
    {
        $allowed = $this->account->type->amortises()
            ? [self::TAB_OVERVIEW, self::TAB_SCHEDULE]
            : [self::TAB_OVERVIEW];

        if (! in_array($this->tab, $allowed, true)) {
            $this->tab = self::TAB_OVERVIEW;
        }
    }
}
