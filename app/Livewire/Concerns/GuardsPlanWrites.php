<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\PlanRefusal;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\PlanWriter;
use Flux\Flux;

/**
 * What the plan editors do when PlanWriter refuses a plan: a plan the user may not write (recurring off, or
 * pay cycle income) closes the form with a toast, and any other refusal shows on the field the component names.
 * The refusal itself is always PlanWriter's; nothing here decides it.
 */
trait GuardsPlanWrites
{
    /** The form field a refusal belongs on, or null to close the form with a toast. */
    abstract protected function planRefusalField(PlanRefusal $refusal): ?string;

    abstract protected function dismissGuardedPlan(): void;

    /**
     * Whether the plan may be opened or changed; when not, closes the form and says why.
     *
     * @param  bool  $allowPayCycleOccurrence  a past occurrence of pay cycle income is recorded as a transaction, not edited
     */
    protected function guardEditablePlan(PlannedTransaction $plan, bool $allowPayCycleOccurrence = false): bool
    {
        $refusal = app(PlanWriter::class)->refusalFor($this->planOwner(), $plan);

        if ($refusal === null || ($allowPayCycleOccurrence && $refusal === PlanRefusal::PayCycleIncome)) {
            return true;
        }

        $this->closeWithToast($refusal);

        return false;
    }

    protected function showPlanRefusal(PlanRefusal $refusal): void
    {
        $field = $this->planRefusalField($refusal);

        if ($field === null) {
            $this->closeWithToast($refusal);

            return;
        }

        $this->addError($field, $refusal->message());
    }

    private function closeWithToast(PlanRefusal $refusal): void
    {
        $this->dismissGuardedPlan();
        Flux::toast(text: $refusal->message(), variant: 'warning');
    }

    private function planOwner(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
