@use('App\Casts\MoneyCast')

@php
    $trend = $this->trend;
    $peak = max(1, max($trend['previous']), max($trend['current']));
    $step = $trend['days'] > 1 ? 300 / ($trend['days'] - 1) : 0;
    $points = fn (array $series): string => collect(count($series) === 1 ? [$series[0], $series[0]] : $series)->map(fn (int $value, int $index): string => round(($index === 1 && count($series) === 1 ? 0 : $index) * $step, 1).','.round(100 - max(0, $value) / $peak * 100, 1))->implode(' ');
@endphp

<section class="space-y-3" data-test="spending-trend">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <x-cib.sec-head :title="__('Spending trend')"/>
        <div class="flex items-center gap-2">
            <input type="month" wire:change="$set('month', $event.target.value)" max="{{ $currentMonth }}" value="{{ $this->selectedMonth->format('Y-m') }}" class="rounded border border-zinc-300 px-2 py-1 text-sm" aria-label="{{ __('Month') }}" data-test="spending-trend-month">
            <select wire:model.live="category" class="rounded border border-zinc-300 px-2 py-1 text-sm" aria-label="{{ __('Category') }}" data-test="spending-trend-category">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->parent_id === null ? '' : '— ' }}{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <div class="text-3xl font-black" data-test="spending-trend-total">{{ MoneyCast::format($trend['currentTotal']) }}</div>
        <p @class(['text-sm', 'text-red-600' => $trend['delta'] > 0, 'text-cib-green-600' => $trend['delta'] <= 0]) data-test="spending-trend-delta">
            {{ ($trend['delta'] >= 0 ? '+' : '-').MoneyCast::format(abs($trend['delta'])) }} {{ __('against the first :days days of last month', ['days' => $trend['elapsed']]) }}
        </p>
    </div>

    <svg viewBox="0 0 300 100" class="h-32 w-full" preserveAspectRatio="none" role="img" aria-label="{{ __('Cumulative spending this month and last month') }}" data-test="spending-trend-chart">
        <polyline fill="none" stroke="#a1a1aa" stroke-width="1.5" stroke-dasharray="4 3" vector-effect="non-scaling-stroke" points="{{ $points($trend['previous']) }}" data-test="spending-trend-previous"/>
        <polyline fill="none" stroke="#ef4444" stroke-width="2" vector-effect="non-scaling-stroke" points="{{ $points($trend['current']) }}" data-test="spending-trend-current"/>
    </svg>

    <p class="text-sm text-gray-500">{{ $this->selectedMonth->format('F Y') }} · <span class="text-gray-400">{{ __('last month') }}</span></p>
</section>
