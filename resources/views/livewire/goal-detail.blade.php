@use('App\Casts\MoneyCast')
@use('App\Enums\GoalHealth')
@use('App\Enums\GoalKind')
@use('App\Enums\GoalStatus')
@php
    $goal = $this->goal;
    $progress = $this->progress;
    $projection = $this->projection;
    $status = $goal->status;
    $series = $projection->saved;
    $maxY = max([$projection->targetAmount, $projection->projectedEnd, 1, ...array_column($series, 'amount')]);
    $width = 300;
    $height = 120;
    $points = [];
    $count = count($series);
    foreach ($series as $index => $point) {
        $points[] = round($count > 1 ? $index / ($count) * ($width * 0.7) : 0, 1).','.round($height - ($point['amount'] / $maxY) * $height, 1);
    }
    $startX = $count > 0 ? round(($count - 1) / $count * ($width * 0.7), 1) : 0;
    $startY = $count > 0 ? $height - (end($series)['amount'] / $maxY) * $height : $height - ($progress->savedAmount / $maxY) * $height;
    $targetY = round($height - ($projection->targetAmount / $maxY) * $height, 1);
    $endY = round($height - ($projection->projectedEnd / $maxY) * $height, 1);
    $circumference = 100;
@endphp
<div class="space-y-6" data-testid="goal-detail">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('goals') }}" wire:navigate class="text-sm text-zinc-500 hover:underline">← {{ __('Goals') }}</a>
            <flux:heading size="xl">{{ $goal->name }}</flux:heading>
            <flux:badge size="sm" data-testid="goal-status">{{ ucfirst($status->value) }}</flux:badge>
        </div>
        <div class="flex flex-wrap gap-2" data-testid="goal-actions">
            @if ($status === GoalStatus::Active)
                <flux:button size="sm" wire:click="pause" data-testid="pause-goal">{{ __('Pause') }}</flux:button>
            @endif
            @if ($status === GoalStatus::Paused)
                <flux:button size="sm" wire:click="resume" data-testid="resume-goal">{{ __('Resume') }}</flux:button>
            @endif
            @if ($goal->kind === GoalKind::OneOff && in_array($status, [GoalStatus::Active, GoalStatus::Paused], true))
                <flux:button size="sm" wire:click="complete" data-testid="complete-goal">{{ __('Complete') }}</flux:button>
            @endif
            @if ($status === GoalStatus::Completed)
                <flux:button size="sm" wire:click="reopen" data-testid="reopen-goal">{{ __('Reopen') }}</flux:button>
            @endif
            @if ($status !== GoalStatus::Archived)
                <flux:button size="sm" wire:click="archive" data-testid="archive-goal">{{ __('Archive') }}</flux:button>
            @else
                <flux:button size="sm" wire:click="unarchive" data-testid="unarchive-goal">{{ __('Unarchive') }}</flux:button>
            @endif
            <flux:button size="sm" variant="danger" wire:click="delete" wire:confirm="{{ __('Delete this goal? Accounts and transactions are untouched.') }}" data-testid="delete-goal">{{ __('Delete') }}</flux:button>
        </div>
    </div>

    @error('goal')
        <flux:callout variant="danger" icon="exclamation-triangle" data-testid="goal-error"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>
    @enderror

    <div class="grid gap-4 lg:grid-cols-2">
        <flux:card class="flex items-center gap-6" data-testid="goal-progress">
            <div class="relative size-36 shrink-0">
                <svg viewBox="0 0 36 36" class="size-36 -rotate-90" role="img" aria-label="{{ __(':percent% saved', ['percent' => $progress->percent]) }}">
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" class="stroke-zinc-200 dark:stroke-zinc-700"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke-width="4" stroke-dasharray="{{ $progress->percent }} {{ $circumference - $progress->percent }}" class="stroke-teal-500"/>
                </svg>
                <div class="absolute inset-0 flex items-center justify-center text-xl font-semibold" data-testid="goal-percent">{{ $progress->percent }}%</div>
            </div>
            <div class="space-y-1 text-sm">
                <div><span class="text-zinc-500">{{ __('Saved') }}</span> <b data-testid="goal-saved">{{ MoneyCast::format($progress->savedAmount) }}</b></div>
                <div><span class="text-zinc-500">{{ __('Target') }}</span> {{ MoneyCast::format($progress->targetAmount) }}</div>
                <div><span class="text-zinc-500">{{ __('Remaining') }}</span> {{ MoneyCast::format($progress->remainingAmount) }}</div>
                <div><span class="text-zinc-500">{{ __('Pace') }}</span> {{ __(':amount a month', ['amount' => MoneyCast::format($progress->pacePerMonth)]) }}</div>
                @if ($progress->daysToTarget !== null)
                    <div data-testid="goal-days">
                        @if ($progress->daysToTarget >= 0)
                            {{ trans_choice(':count day left|:count days left', $progress->daysToTarget) }}
                        @else
                            {{ trans_choice(':count day past due|:count days past due', abs($progress->daysToTarget)) }}
                        @endif
                    </div>
                @endif
                @if ($this->lastMatchedDays !== null)
                    <div class="text-zinc-500" data-testid="goal-last-matched">{{ trans_choice('Last matched :count day ago|Last matched :count days ago', $this->lastMatchedDays) }}</div>
                @endif
            </div>
        </flux:card>

        <flux:card class="space-y-3" data-testid="goal-callout">
            @if ($status === GoalStatus::Paused)
                <flux:callout icon="pause-circle" data-testid="paused-callout">
                    <flux:callout.heading>{{ __('Paused') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('This goal keeps its earmarks but is not counted as behind. Resume it to see what you need to save.') }}</flux:callout.text>
                </flux:callout>
            @else
            @switch($progress->health)
                @case(GoalHealth::Behind)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Behind') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Save :amount a month to catch up.', ['amount' => MoneyCast::format($progress->monthlyTarget ?? $progress->remainingAmount)]) }}</flux:callout.text>
                    </flux:callout>
                    @break
                @case(GoalHealth::OnTrack)
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>{{ __('On track') }}</flux:callout.heading>
                        <flux:callout.text>{{ $goal->target_date ? __('At this pace you reach the goal by :date.', ['date' => $goal->target_date->format('j M Y')]) : __('You are saving at the pace needed.') }}</flux:callout.text>
                    </flux:callout>
                    @break
                @case(GoalHealth::Depleted)
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Depleted') }}</flux:callout.heading>
                        <flux:callout.text>{{ __(':amount short of the reserve.', ['amount' => MoneyCast::format($progress->remainingAmount)]) }}</flux:callout.text>
                    </flux:callout>
                    @break
                @case(GoalHealth::Funded)
                    <flux:callout variant="success" icon="check-circle"><flux:callout.heading>{{ __('Funded') }}</flux:callout.heading><flux:callout.text>{{ __('The reserve is at or above its floor.') }}</flux:callout.text></flux:callout>
                    @break
                @case(GoalHealth::Reached)
                    <flux:callout variant="success" icon="check-circle"><flux:callout.heading>{{ __('Reached') }}</flux:callout.heading><flux:callout.text>{{ __('Nothing remains to save.') }}</flux:callout.text></flux:callout>
                    @break
                @default
                    <flux:callout icon="information-circle"><flux:callout.heading>{{ __('No target date') }}</flux:callout.heading><flux:callout.text>{{ __('Set a date to see what you need to save each month.') }}</flux:callout.text></flux:callout>
            @endswitch
            @endif
        </flux:card>
    </div>

    <flux:card class="space-y-2" data-testid="goal-projection">
        <flux:heading size="lg">{{ __('Projection') }}</flux:heading>
        <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-40 w-full" role="img" aria-label="{{ __('Saved balance and projection to the target date') }}">
            <line x1="0" y1="{{ $targetY }}" x2="{{ $width }}" y2="{{ $targetY }}" stroke-dasharray="4 3" class="stroke-amber-500" stroke-width="1" data-testid="target-line"/>
            @if ($points !== [])
                <polyline points="{{ implode(' ', $points) }}" fill="none" class="stroke-teal-500" stroke-width="2" data-testid="saved-series"/>
            @endif
            <line x1="{{ $startX }}" y1="{{ round($startY, 1) }}" x2="{{ $width }}" y2="{{ $endY }}" stroke-dasharray="3 3" class="stroke-zinc-500" stroke-width="1.5" data-testid="projected-line"/>
        </svg>
        <div class="flex flex-wrap justify-between gap-2 text-sm">
            <span data-testid="projected-end">{{ __('Projected :amount by :date', ['amount' => MoneyCast::format($projection->projectedEnd), 'date' => $projection->projectedOn->format('j M Y')]) }}</span>
            @if ($projection->shortfall > 0)
                <span class="text-red-600 dark:text-red-500" data-testid="projected-shortfall">{{ __('Shortfall :amount', ['amount' => MoneyCast::format($projection->shortfall)]) }}</span>
            @endif
        </div>
    </flux:card>

    <flux:card class="space-y-2" data-testid="goal-funding">
        <flux:heading size="lg">{{ __('Funding') }}</flux:heading>
        @foreach ($this->funding as $row)
            <div wire:key="funding-{{ $row['account']->id }}" class="flex justify-between text-sm">
                <span>{{ $row['account']->name }} <span class="text-zinc-500">({{ $row['earmark'] === null ? __('whole balance') : __('earmark :amount', ['amount' => MoneyCast::format($row['earmark'])]) }})</span></span>
                <span>{{ MoneyCast::format($row['backing']) }}</span>
            </div>
        @endforeach
    </flux:card>

    <flux:card class="space-y-3" data-testid="goal-pledges">
        <flux:heading size="lg">{{ __('Pending pledges') }}</flux:heading>
        @forelse ($this->pledges as $pledge)
            <div wire:key="pledge-{{ $pledge->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm" data-testid="pledge-row">
                <span>{{ MoneyCast::format($pledge->amount) }} · {{ $pledge->account->name }} · {{ __('open until :date', ['date' => $pledge->expires_at->format('j M')]) }}</span>
                <span class="flex gap-1">
                    <flux:button size="xs" wire:click="renewPledge({{ $pledge->id }})" data-testid="renew-pledge">{{ __('Renew') }}</flux:button>
                    <flux:button size="xs" variant="ghost" wire:click="cancelPledge({{ $pledge->id }})" data-testid="cancel-pledge">{{ __('Cancel') }}</flux:button>
                </span>
            </div>
        @empty
            <flux:text size="sm" class="text-zinc-500">{{ __('No pending pledges.') }}</flux:text>
        @endforelse
        <form wire:submit="pledge" class="grid gap-3 border-t border-neutral-200 pt-3 sm:grid-cols-3 dark:border-neutral-700" data-testid="pledge-form">
            <flux:select wire:model="pledgeAccountId" size="sm" label="{{ __('Account') }}">
                <flux:select.option value="">{{ __('Choose an account') }}</flux:select.option>
                @foreach ($this->funding as $row)
                    <flux:select.option value="{{ $row['account']->id }}">{{ $row['account']->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="pledgeAmount" type="number" step="0.01" min="0" size="sm" label="{{ __('I just saved') }}"/>
            <div class="flex items-end"><flux:button type="submit" size="sm" variant="filled" data-testid="pledge-submit">{{ __('Pledge') }}</flux:button></div>
        </form>
    </flux:card>

    @if ($goal->kind === GoalKind::OneOff && $status === GoalStatus::Active)
        <flux:card data-testid="goal-spend">
            <form wire:submit="recordSpend" class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model="spendAccountId" size="sm" label="{{ __('Spent from') }}">
                    <flux:select.option value="">{{ count($this->funding) === 1 ? __('The only account') : __('Choose an account') }}</flux:select.option>
                    @foreach ($this->funding as $row)
                        <flux:select.option value="{{ $row['account']->id }}">{{ $row['account']->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="spendAmount" type="number" step="0.01" min="0" size="sm" label="{{ __('Amount spent') }}"/>
                <div class="flex items-end"><flux:button type="submit" size="sm" variant="filled" data-testid="spend-submit">{{ __('Record spend') }}</flux:button></div>
            </form>
        </flux:card>
    @endif
</div>
