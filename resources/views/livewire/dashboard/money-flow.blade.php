@use('App\Casts\MoneyCast')

@php($peak = max(1, ...array_map(fn (array $bar): int => max($bar['income'], $bar['expense']), $this->bars)))

<section class="space-y-3" data-test="money-flow">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-cib.sec-head :title="__('Money flow')"/>
        <div class="flex items-center gap-2">
            <input type="month" wire:change="$set('month', $event.target.value)" max="{{ $currentMonth }}" value="{{ $this->selectedMonth->format('Y-m') }}" class="rounded border border-zinc-300 px-2 py-1 text-sm" aria-label="{{ __('Month') }}" data-test="money-flow-month">
            <select wire:model.live="account" class="rounded border border-zinc-300 px-2 py-1 text-sm" aria-label="{{ __('Account') }}" data-test="money-flow-account">
                <option value="">{{ __('All accounts') }}</option>
                @foreach ($this->accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="flex h-40 items-end gap-3" data-test="money-flow-bars">
        @foreach ($this->bars as $bar)
            <div class="flex flex-1 flex-col items-center gap-1" data-test="money-flow-month-{{ $bar['key'] }}" @if ($bar['partial']) data-partial="true" @endif>
                <div class="flex h-32 w-full items-end justify-center gap-1">
                    <div class="w-1/2 rounded-t bg-cib-green-600 {{ $bar['partial'] ? 'opacity-60' : '' }}" style="height: {{ round($bar['income'] / $peak * 100) }}%" title="{{ __('Income') }} {{ MoneyCast::format($bar['income']) }}" data-test="money-flow-income">
                        <span class="sr-only">{{ __('Income') }} {{ MoneyCast::format($bar['income']) }}</span>
                    </div>
                    <div class="w-1/2 rounded-t bg-red-500 {{ $bar['partial'] ? 'opacity-60' : '' }}" style="height: {{ round($bar['expense'] / $peak * 100) }}%" title="{{ __('Expenses') }} {{ MoneyCast::format($bar['expense']) }}" data-test="money-flow-expense">
                        <span class="sr-only">{{ __('Expenses') }} {{ MoneyCast::format($bar['expense']) }}</span>
                    </div>
                </div>
                <div class="text-xs text-gray-500">{{ $bar['label'] }}@if ($bar['partial']) <span data-test="money-flow-partial">{{ __('(to date)') }}</span>@endif</div>
            </div>
        @endforeach
    </div>

    <p class="text-sm text-gray-500">
        <span class="text-cib-green-600">{{ __('Income') }}</span> · <span class="text-red-600">{{ __('Expenses') }}</span>
    </p>
</section>
