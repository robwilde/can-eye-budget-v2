<?php

use App\Enums\PlannedTransactionStatus;
use App\Jobs\GeneratePlannedOccurrencesJob;
use App\Models\PlannedTransaction;
use App\Models\User;
use App\Services\Recurring\RecurringGate;
use App\Services\Recurring\RecurringScanner;
use App\Services\Recurring\StalePlanSweeper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recurring settings')] class extends Component {
    public string $notice = '';

    public function toggleRecurring(): void
    {
        $user = $this->user();

        $enable = ! RecurringGate::enabled($user);

        $user->updatePreferences(['recurringEnabled' => $enable]);

        if ($enable) {
            GeneratePlannedOccurrencesJob::dispatch($user, rebuildHistory: true);
        }

        $this->notice = '';
    }

    public function identifyPatterns(RecurringScanner $scanner): void
    {
        $user = $this->user();

        if (! RecurringGate::enabled($user)) {
            $this->notice = __('Recurring is switched off.');

            return;
        }

        $count = $scanner->run($user);

        $this->notice = $count === null
            ? __('A scan is already running. Try again in a moment.')
            : trans_choice('Found :count new recurring suggestion|Found :count new recurring suggestions', $count, ['count' => $count]);
    }

    public function cleanUpStale(StalePlanSweeper $sweeper): void
    {
        $user = $this->user();

        if (! RecurringGate::enabled($user)) {
            $this->notice = __('Recurring is switched off.');

            return;
        }

        $result = $sweeper->sweepForUser($user);

        $this->notice = __(':retired plan(s) paused, :discarded removed.', ['retired' => $result['retired'], 'discarded' => $result['discarded']]);
    }

    public function togglePlan(int $planId): void
    {
        $plan = $this->plan($planId);

        DB::transaction(fn () => $plan->update(['status' => $plan->status->toggled()]));
    }

    public function deletePlan(int $planId): void
    {
        $plan = $this->plan($planId);

        DB::transaction(fn () => $plan->discard());
    }

    private function plan(int $planId): PlannedTransaction
    {
        $plan = PlannedTransaction::query()->where('user_id', $this->user()->id)->visible()->where(fn ($query) => $query->whereNull('is_pay_cycle_income')->orWhere('is_pay_cycle_income', false))->find($planId);

        abort_unless($plan instanceof PlannedTransaction, 404);

        return $plan;
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function with(): array
    {
        $user = $this->user();

        return [
            'enabled' => RecurringGate::enabled($user),
            'plans' => PlannedTransaction::query()->where('user_id', $user->id)->visible()->orderBy('status')->orderBy('description')->get(),
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Recurring settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Recurring')" :subheading="__('Bills and income that repeat')">
        <div class="my-6 w-full space-y-6">
            <flux:switch
                wire:click="toggleRecurring"
                :checked="$enabled"
                :label="__('Recurring bills and income')"
                :description="__('Switching off stops detection and occurrence generation and hides Bills. Your plans are kept.')"
                data-testid="recurring-switch"
            />

            <div class="flex flex-wrap gap-2">
                <flux:button wire:click="identifyPatterns" :disabled="! $enabled" data-testid="identify-patterns">{{ __('Identify patterns') }}</flux:button>
                <flux:button wire:click="cleanUpStale" :disabled="! $enabled" variant="ghost" data-testid="clean-up-stale">{{ __('Clean up stale') }}</flux:button>
            </div>

            @if($notice !== '')
                <flux:text data-testid="recurring-notice" role="status">{{ $notice }}</flux:text>
            @endif

            <div data-testid="recurring-plans">
                @forelse($plans as $plan)
                    <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 py-2 text-sm" wire:key="plan-{{ $plan->id }}" data-testid="recurring-plan-{{ $plan->id }}">
                        <span class="min-w-[8rem] flex-1">{{ $plan->description }}</span>
                        <flux:badge size="sm" :color="$plan->isActive() ? 'green' : 'yellow'">{{ $plan->status->label() }}</flux:badge>
                        @unless($plan->is_pay_cycle_income)
                            <flux:button size="sm" variant="ghost" wire:click="togglePlan({{ $plan->id }})" data-testid="plan-pause-{{ $plan->id }}">{{ $plan->isActive() ? __('Pause') : __('Resume') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="deletePlan({{ $plan->id }})" wire:confirm="{{ __('Delete this plan?') }}" data-testid="plan-delete-{{ $plan->id }}">{{ __('Delete') }}</flux:button>
                        @endunless
                    </div>
                @empty
                    <flux:text size="sm" class="text-zinc-500">{{ __('No plans yet.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>
</section>
