<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\TransferRule;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Remembered transfers, viewable and deletable next to the user's other rules. */
final class TransferRuleList extends Component
{
    /** Re-renders after the review page remembers a rule (confirm, link to a hidden account). */
    #[On('transfer-rules-changed')]
    public function refreshRules(): void {}

    /** Deleting a rule only stops future auto-linking; existing links are untouched. */
    public function deleteRule(int $ruleId): void
    {
        TransferRule::query()
            ->where('user_id', $this->authenticatedUser()->id)
            ->findOrFail($ruleId)
            ->delete();

        Flux::toast(text: 'Rule deleted', variant: 'success');
    }

    public function render(): View
    {
        return view('livewire.transfer-rule-list', [
            'rules' => TransferRule::query()
                ->where('user_id', $this->authenticatedUser()->id)
                ->with(['account:id,name', 'counterpartAccount:id,name'])
                ->latest('id')
                ->get(),
        ]);
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
