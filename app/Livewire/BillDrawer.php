<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Attributes\NotAudited;
use App\Models\User;
use App\Services\Bills\BillDetailService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The drawer that tells one bill's whole story. It opens from a row on Bills or a direct link, and only ever for
 * a bill of the signed-in user (an ended one too, for its history): any other id opens nothing and reads nothing.
 */
final class BillDrawer extends Component
{
    public bool $showDrawer = false;

    #[Locked]
    public ?int $planId = null;

    /** @var array<string, string> */
    protected $listeners = [
        'occurrence-payment-changed' => '$refresh',
    ];

    public function mount(?int $planId = null): void
    {
        if ($planId !== null) {
            $this->open($planId);
        }
    }

    #[NotAudited]
    #[On('open-bill-drawer')]
    public function open(int $planId): void
    {
        $plan = app(BillDetailService::class)->find($this->user(), $planId);

        $this->planId = $plan?->id;
        $this->showDrawer = $plan !== null;
    }

    #[NotAudited]
    public function close(): void
    {
        $this->showDrawer = false;
    }

    public function render(): View
    {
        $user = $this->user();
        $plan = $this->showDrawer && $this->planId !== null ? app(BillDetailService::class)->find($user, $this->planId) : null;

        return view('livewire.bill-drawer', [
            'detail' => $plan === null ? null : app(BillDetailService::class)->detail($user, $plan),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
