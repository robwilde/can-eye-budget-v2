@php
    $scale = max($plan, $actual, 1);
    $planPct = round($plan / $scale * 100, 2);
    $actualPct = round($actual / $scale * 100, 2);
    $todayPct = round($planPct * $mf->elapsedDays / $mf->daysInMonth, 2);
    $ticks = $planPct >= 25
        ? array_values(array_unique([1, 8, 15, 22, $mf->daysInMonth]))
        : array_values(array_unique([1, $mf->daysInMonth]));
    $summary = $formatMoney($actual).' '.$verb.'; '
        .($mf->todayLabel !== null ? $formatMoney($plannedToDate).' planned by today; ' : '')
        .$formatMoney($plan).' planned for the month';
    $labels = [];
    if ($mf->todayLabel !== null) {
        $labels[] = ['kind' => 'today', 'pos' => $todayPct, 'text' => $mf->todayLabel.' · '.$mf->elapsedPercent().'% · '.$formatMoney($plannedToDate).' planned to date'];
    }
    $labels[] = ['kind' => 'actual', 'pos' => $actualPct, 'text' => 'Actual '.$formatMoney($actual)];
    $labels[] = ['kind' => 'plan', 'pos' => $planPct, 'text' => 'Plan '.$formatMoney($plan)];
@endphp
<div class="mf-bar mf-bar-{{ $barKey }}" data-testid="money-flow-bar-{{ $barKey }}">
    <h3 class="mf-bar-title">{{ $barLabel }}</h3>
    <div class="mf-bar-track" role="img" aria-label="{{ $barLabel }}: {{ $summary }}">
        <span class="mf-bar-plan" @style(['width: '.$planPct.'%'])></span>
        <span @class(['mf-bar-fill', 'is-over' => $over]) @style(['width: '.$actualPct.'%'])></span>
        <span class="mf-bar-end" @style(['left: '.$planPct.'%'])></span>
        @if ($mf->todayLabel !== null)
            <span class="mf-bar-today" @style(['left: '.$todayPct.'%'])></span>
        @endif
    </div>
    <div class="mf-ticks" aria-hidden="true">
        @foreach ($ticks as $day)
            <span @class(['is-last' => $loop->last]) @style(['left: '.round($planPct * $day / $mf->daysInMonth, 2).'%'])>{{ $day }}</span>
        @endforeach
    </div>
    <div class="mf-bar-labels">
        @foreach ($labels as $label)
            <div class="mf-bar-label-row">
                <span @class(['mf-bar-label', 'is-'.$label['kind'], 'is-end' => $label['pos'] > 50]) @style(['left: '.$label['pos'].'%', '--mf-pos: '.$label['pos'].'%'])>
                    <i aria-hidden="true"></i>{{ $label['text'] }}
                </span>
            </div>
        @endforeach
    </div>
    <p class="sr-only" data-testid="money-flow-bar-{{ $barKey }}-summary">{{ $barLabel }}: {{ $summary }}</p>
</div>
