@php
    $monthFlow = $this->monthFlow;
    $cashFlow = $this->cashFlow;
    $slides = ['Plan vs actual', 'Cash flow', 'Outflows'];
@endphp
<section
    class="mf"
    data-testid="money-flow"
    role="region"
    aria-roledescription="carousel"
    aria-label="Month summary"
    tabindex="0"
    x-data="moneyFlowCarousel(@js($slides))"
    @keydown.arrow-right.prevent="step(1)"
    @keydown.arrow-left.prevent="step(-1)"
>
    <div class="mf-nav">
        <div class="mf-arrows">
            <button type="button" aria-label="Previous view" data-testid="money-flow-prev" @click="step(-1)" :disabled="index === 0">
                <flux:icon.chevron-left variant="micro"/>
            </button>
            <button type="button" aria-label="Next view" data-testid="money-flow-next" @click="step(1)" :disabled="index === labels.length - 1">
                <flux:icon.chevron-right variant="micro"/>
            </button>
        </div>
        <div class="mf-tabs" role="tablist" aria-label="Choose a view">
            <button type="button" role="tab" id="mf-tab-plan" aria-controls="mf-slide-plan" data-testid="money-flow-tab-plan" :aria-selected="index === 0 ? 'true' : 'false'" :class="{ 'is-on': index === 0 }" @click="go(0)">Plan vs actual</button>
            <button type="button" role="tab" id="mf-tab-cash" aria-controls="mf-slide-cash" data-testid="money-flow-tab-cash" :aria-selected="index === 1 ? 'true' : 'false'" :class="{ 'is-on': index === 1 }" @click="go(1)">Cash flow</button>
        </div>
    </div>

    <p class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></p>

    <div class="mf-track" x-ref="track" wire:ignore.self @scroll.debounce.80ms="sync()">
        <div class="mf-slide" id="mf-slide-plan" role="group" aria-roledescription="slide" aria-label="Plan vs actual, view 1 of 3" data-testid="money-flow-slide-plan" :aria-hidden="index === 0 ? 'false' : 'true'" :inert="index !== 0">
            @include('livewire.calendar-view.plan-vs-actual')
        </div>

        <div class="mf-slide" id="mf-slide-cash" role="group" aria-roledescription="slide" aria-label="Cash flow, view 2 of 3" data-testid="money-flow-slide-cash" aria-hidden="true" inert :aria-hidden="index === 1 ? 'false' : 'true'" :inert="index !== 1">
            @include('livewire.calendar-view.cash-flow')
        </div>

        <div class="mf-slide" id="mf-slide-outflows" role="group" aria-roledescription="slide" aria-label="Outflows, view 3 of 3" data-testid="money-flow-slide-outflows" aria-hidden="true" inert :aria-hidden="index === 2 ? 'false' : 'true'" :inert="index !== 2">
            @include('livewire.calendar-view.outflows')
        </div>
    </div>
</section>
