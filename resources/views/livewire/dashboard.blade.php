@use('App\Enums\ChartHeight')
@use('App\Enums\TransactionPeriod')
@use('App\Enums\WidgetWidth')

@php
    $layout = $this->layout;
    $period = $this->period;
@endphp

<div class="space-y-6">
    @if ($this->needsBankConnection)
        <section data-test="dashboard-connect-bank-card" class="flex flex-wrap items-center justify-between gap-4 border-2 border-black rounded-[28px] bg-cib-yellow-400 p-6 shadow-[3px_3px_0_0_#111]">
            <div>
                <h2 class="font-display text-2xl font-black tracking-tight">Connect your bank</h2>
                <p class="mt-2 text-sm">Link your accounts through Redbark so your transactions sync automatically.</p>
            </div>
            <a href="{{ route('connect-bank') }}" wire:navigate class="inline-flex items-center gap-2 rounded-md border-2 border-cib-black bg-white px-4 py-2 text-sm font-bold text-cib-black shadow-pop-sm">
                Connect your bank
            </a>
        </section>
    @endif

    @if ($this->firstImportSummary !== null)
        <x-cib.card data-test="dashboard-first-import-card">
            <flux:heading size="lg">{{ __('Your first import is sorted') }}</flux:heading>
            <flux:text size="sm" class="mt-1" data-test="dashboard-first-import-text">
                {{ trans_choice(':count transaction categorised by :rules|:count transactions categorised by :rules', $this->firstImportSummary['categorised'], [
                    'rules' => trans_choice(':count new rule|:count new rules', $this->firstImportSummary['rules']),
                ]) }}, {{ trans_choice(':count needs your attention.|:count need your attention.', $this->firstImportSummary['needs_attention']) }}
            </flux:text>
            @if ($this->firstImportSummary['needs_attention'] > 0)
                <a href="{{ route('transactions', ['categorised' => 'uncategorised', 'period' => TransactionPeriod::All->value]) }}" wire:navigate
                   class="mt-3 inline-block text-sm font-bold underline decoration-dotted"
                   data-test="dashboard-first-import-link">{{ __('Categorise the rest') }}</a>
            @endif
        </x-cib.card>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3" data-testid="dashboard-controls">
        <div class="flex flex-wrap items-center gap-2" data-testid="dashboard-period" aria-label="{{ __('Period') }}">
            @foreach (TransactionPeriod::dashboardOptions() as $option)
                <button type="button"
                        wire:click="setPeriod('{{ $option->value }}')"
                        @class(['report-chip', 'active' => $period->period === $option])
                        data-testid="dashboard-period-{{ $option->value }}"
                        title="{{ $option->label() }}">{{ $option->shortLabel() }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-2">
            <flux:text size="sm" data-testid="dashboard-period-label">{{ $period->label() }}</flux:text>
            <button type="button" wire:click="toggleCustomise" @class(['report-chip', 'active' => $customising]) data-testid="dashboard-customise">
                {{ $customising ? __('Done') : __('Customise') }}
            </button>
        </div>
    </div>

    @if ($this->showCustomRange)
        <div class="flex flex-wrap items-end gap-3" data-testid="dashboard-custom-range">
            <flux:input type="date" wire:model="customFrom" size="sm" class="w-auto" aria-label="{{ __('From') }}"/>
            <flux:input type="date" wire:model="customTo" size="sm" class="w-auto" aria-label="{{ __('To') }}"/>
            <flux:button size="sm" wire:click="setCustomRange" data-testid="dashboard-custom-apply">{{ __('Apply') }}</flux:button>
        </div>
    @endif

    @if ($customising)
        <div class="flex flex-wrap items-center gap-3 rounded-xl border-2 border-dashed border-cib-black p-3" data-testid="dashboard-customise-bar">
            <flux:text size="sm">{{ __('Drag widgets to reorder them, collapse or hide them, and set their size.') }}</flux:text>
            <button type="button" wire:click="setTwoColumn({{ $layout->twoColumn ? 'false' : 'true' }})" class="report-chip" data-testid="dashboard-two-column">
                {{ $layout->twoColumn ? __('Two columns') : __('One column') }}
            </button>
            <button type="button" wire:click="resetLayout" class="report-chip" data-testid="dashboard-reset-layout">{{ __('Reset layout') }}</button>
        </div>
    @endif

    <div wire:key="dashboard-grid-{{ $customising ? 'edit' : 'view' }}"
         @class(['grid gap-4', 'lg:grid-cols-2' => $layout->twoColumn])
         @if ($customising) wire:sort="reorder" @endif
         data-testid="dashboard-grid">
        @foreach ($this->visibleWidgets as $widget)
            @php
                $collapsed = $layout->isCollapsed($widget);
                ob_start();
            @endphp
            @if (! $collapsed)
                @if ($widget->component() !== null)
                    @livewire($widget->component(), $widget->componentParams($period->stamp()), key('widget-'.$widget->value))
                @else
                    @include($widget->view(), ['dashboard' => $this])
                @endif
            @endif
            @php
                $content = trim((string) ob_get_clean());
                $hasContent = trim(str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', $content)) !== '';
            @endphp

            @if ($customising || $collapsed || $hasContent)
                <div wire:key="widget-{{ $widget->value }}"
                     @if ($customising) wire:sort:item="{{ $widget->value }}" @endif
                     data-widget="{{ $widget->value }}"
                     @class([
                         'space-y-2',
                         'lg:col-span-2' => $layout->twoColumn && $layout->width($widget) === WidgetWidth::Full,
                         $layout->height($widget)->minHeightClass() => $widget->isChart() && ! $collapsed,
                         'rounded-xl border-2 border-dashed border-cib-black p-2' => $customising,
                     ])>
                    @if ($customising || $collapsed)
                        <div class="flex flex-wrap items-center justify-between gap-2" data-testid="widget-toolbar-{{ $widget->value }}">
                            <div class="flex items-center gap-2">
                                @if ($customising)
                                    <span wire:sort:handle class="cursor-grab select-none" aria-label="{{ __('Drag to reorder') }}" data-testid="widget-handle-{{ $widget->value }}">⋮⋮</span>
                                @endif
                                <flux:heading size="sm">{{ __($widget->label()) }}</flux:heading>
                            </div>
                            <div class="flex flex-wrap items-center gap-1">
                                <button type="button" wire:click="toggleCollapsed('{{ $widget->value }}')" class="report-chip" data-testid="widget-collapse-{{ $widget->value }}">
                                    {{ $collapsed ? __('Expand') : __('Collapse') }}
                                </button>
                                @if ($customising)
                                    @if ($layout->twoColumn)
                                        @foreach (WidgetWidth::cases() as $width)
                                            <button type="button" wire:click="setWidth('{{ $widget->value }}', '{{ $width->value }}')"
                                                    @class(['report-chip', 'active' => $layout->width($widget) === $width])
                                                    data-testid="widget-width-{{ $widget->value }}-{{ $width->value }}">{{ __($width->label()) }}</button>
                                        @endforeach
                                    @endif
                                    @if ($widget->isChart())
                                        @foreach (ChartHeight::cases() as $height)
                                            <button type="button" wire:click="setChartHeight('{{ $widget->value }}', '{{ $height->value }}')"
                                                    @class(['report-chip', 'active' => $layout->height($widget) === $height])
                                                    data-testid="widget-height-{{ $widget->value }}-{{ $height->value }}">{{ __($height->label()) }}</button>
                                        @endforeach
                                    @endif
                                    <button type="button" wire:click="hideWidget('{{ $widget->value }}')" class="report-chip" data-testid="widget-hide-{{ $widget->value }}">{{ __('Hide') }}</button>
                                @endif
                            </div>
                        </div>
                    @endif
                    @if (! $collapsed)
                        {!! $content !!}
                    @endif
                </div>
            @endif
        @endforeach
    </div>

    @if ($customising)
        <section data-testid="dashboard-hidden-widgets">
            <x-cib.sec-head title="Hidden widgets"/>
            @forelse ($this->hiddenOffers as $widget)
                <button type="button" wire:key="hidden-{{ $widget->value }}" wire:click="showWidget('{{ $widget->value }}')" class="report-chip" data-testid="widget-show-{{ $widget->value }}">
                    {{ __('Add :widget', ['widget' => __($widget->label())]) }}
                </button>
            @empty
                <p class="text-sm text-gray-500">{{ __('Nothing is hidden.') }}</p>
            @endforelse
        </section>
    @endif
</div>
