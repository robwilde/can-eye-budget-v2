<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\DTOs\PaycheckPlan as PaycheckPlanData;
use App\Models\User;
use App\Services\Projection\PaycheckPlanner;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The dashboard's paycheck plan: the coming pay periods with what each paycheck has to cover and what is safe to
 * spend, three by default and up to six, or a prompt to declare income when there is none. It reads PaycheckPlanner
 * for the signed-in user only.
 *
 * @property-read PaycheckPlanData $plan
 */
final class PaycheckPlan extends Component
{
    public const int DEFAULT_PERIODS = PaycheckPlanner::DEFAULT_PERIODS;

    public const int MAX_PERIODS = PaycheckPlanner::MAX_PERIODS;

    public int $periods = self::DEFAULT_PERIODS;

    /** @var array<string, string> */
    protected $listeners = [
        'plan-declared' => '$refresh',
        'transaction-saved' => '$refresh',
        'occurrence-payment-changed' => '$refresh',
    ];

    public function showPeriods(int $count): void
    {
        $this->periods = max(1, min(self::MAX_PERIODS, $count));
    }

    #[Computed]
    public function plan(): PaycheckPlanData
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return app(PaycheckPlanner::class)->plan($user, $this->periods);
    }

    public function placeholder(): string
    {
        return <<<'HTML'
            <section class="paycheck-plan">
                <div class="animate-pulse h-6 w-56 rounded bg-(--color-cib-n-100)"></div>
                <div class="animate-pulse h-32 mt-4 rounded bg-(--color-cib-n-100)"></div>
            </section>
            HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.paycheck-plan');
    }
}
