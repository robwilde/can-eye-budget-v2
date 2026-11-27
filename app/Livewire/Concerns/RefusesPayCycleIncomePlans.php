<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\PlannedTransaction;
use Flux\Flux;

/**
 * The refusal shared by the plan editors: the pay cycle income plan is managed by the pay cycle settings,
 * so no plan form may edit, convert or delete it. The using component closes its own form.
 */
trait RefusesPayCycleIncomePlans
{
    abstract protected function dismissGuardedPlan(): void;

    protected function guardEditablePlan(PlannedTransaction $plan): bool
    {
        if (! $plan->is_pay_cycle_income) {
            return true;
        }

        $this->dismissGuardedPlan();
        Flux::toast(text: 'Pay cycle income is managed elsewhere', variant: 'warning');

        return false;
    }
}
