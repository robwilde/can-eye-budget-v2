<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\BillAuditReport;
use App\Models\User;
use App\Services\Bills\BillAuditor;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * A read-only bill audit on the rules page. The look-back is the only input; the report is a computed property of
 * the signed-in user's own plans, and a look-back outside one to twenty-four months builds no report.
 *
 * @property-read BillAuditReport $baseReport
 * @property-read ?BillAuditReport $report
 * @property-read ?string $lookbackError
 */
final class BillAuditPanel extends Component
{
    public string $lookback = '12';

    #[Computed(persist: true)]
    public function baseReport(): BillAuditReport
    {
        return app(BillAuditor::class)->audit($this->user());
    }

    #[Computed]
    public function report(): ?BillAuditReport
    {
        $base = $this->baseReport;

        if (! $base->enabled) {
            return $base;
        }

        try {
            $months = $this->lookbackMonths();
            $priceChanges = app(BillAuditor::class)->priceChanges($this->user(), $months);
        } catch (ValidationException) {
            return null;
        }

        return new BillAuditReport(true, $months, [...$base->sections, 'priceChanges' => $priceChanges]);
    }

    #[Computed]
    public function lookbackError(): ?string
    {
        try {
            BillAuditor::assertLookback($this->lookbackMonths());
        } catch (ValidationException $refused) {
            return (string) collect($refused->errors())->flatten()->first();
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.bill-audit-panel');
    }

    private function lookbackMonths(): int
    {
        return preg_match('/^\d{1,3}$/', mb_trim($this->lookback)) === 1 ? (int) $this->lookback : 0;
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
