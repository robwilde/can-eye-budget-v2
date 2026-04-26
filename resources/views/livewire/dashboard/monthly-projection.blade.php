@use('App\Casts\MoneyCast')

<section class="monthly-projection">
    @if ($this->projection->isEmpty())
        <x-cib.empty-state
                icon="chart-bar-square"
                title="12-month projection unavailable"
                description="Set up your pay cycle and a few planned transactions to forecast the next year."
        >
            <x-slot:action>
                <a class="link" href="{{ route('pay-cycle.edit') }}">
                    Configure pay cycle →
                </a>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <header class="proj-head">
            <div>
                <h3>Next 12 months</h3>
                <p class="proj-sub">
                    Income smoothed across pay cycles · expenses from active planned transactions.
                </p>
            </div>
            <div class="proj-legend">
                <span class="lg lg-inc"><span class="sw"></span>Income</span>
                <span class="lg lg-exp"><span class="sw"></span>Expenses</span>
                <span class="lg lg-cum"><span class="sw"></span>Cumulative buffer</span>
            </div>
        </header>

        @if ($this->firstRiskyMonth !== null)
            <div class="proj-warn">
                <flux:icon.exclamation-triangle variant="micro"/>
                <div>
                    <b>{{ $this->firstRiskyMonth->label }} {{ $this->firstRiskyMonth->year }}</b>
                    is the first month where planned spend exceeds projected income.
                    Expenses {{ MoneyCast::format($this->firstRiskyMonth->expenseCents) }} vs income {{ MoneyCast::format($this->firstRiskyMonth->incomeCents) }}.
                </div>
            </div>
        @endif

        <div
                wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        const payload = @js($this->chartPayload, JSON_THROW_ON_ERROR);
                        const riskyIndexSet = new Set(payload.riskyIndexes);

                        const formatMoney = (cents) => {
                            const dollars = Math.abs(cents / 100);
                            const formatted = dollars.toLocaleString('en-AU', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            });
                            return (cents < 0 ? '-$' : '$') + formatted;
                        };

                        const expenseColors = payload.expense.map((_, idx) =>
                            riskyIndexSet.has(idx) ? '#E5484D' : '#FBA3A6'
                        );

                        this.chart = new ApexCharts(this.$refs.canvas, {
                            chart: {
                                type: 'line',
                                height: 320,
                                toolbar: { show: false },
                                fontFamily: 'Lato, ui-sans-serif, system-ui, sans-serif',
                            },
                            stroke: {
                                width: [0, 0, 3],
                                curve: 'smooth',
                            },
                            series: [
                                { name: 'Income', type: 'bar', data: payload.income },
                                { name: 'Expenses', type: 'bar', data: payload.expense },
                                { name: 'Cumulative', type: 'line', data: payload.cumulative },
                            ],
                            colors: ['#2AAF46', '#E5484D', '#0E5E57'],
                            fill: {
                                opacity: [1, 1, 1],
                                colors: ['#2AAF46', ({ seriesIndex, dataPointIndex }) => {
                                    if (seriesIndex !== 1) {
                                        return '#0E5E57';
                                    }
                                    return expenseColors[dataPointIndex];
                                }, '#0E5E57'],
                            },
                            plotOptions: {
                                bar: {
                                    columnWidth: '60%',
                                    borderRadius: 3,
                                },
                            },
                            dataLabels: { enabled: false },
                            legend: { show: false },
                            grid: {
                                borderColor: '#DBDEDD',
                                strokeDashArray: 3,
                            },
                            xaxis: {
                                categories: payload.categories,
                                labels: { style: { fontWeight: 700, fontSize: '11px' } },
                                axisBorder: { color: '#111' },
                                axisTicks: { color: '#111' },
                            },
                            yaxis: {
                                labels: {
                                    formatter: (value) => formatMoney(value),
                                    style: { fontWeight: 700, fontSize: '10px' },
                                },
                            },
                            tooltip: {
                                shared: true,
                                intersect: false,
                                y: { formatter: (value) => formatMoney(value) },
                            },
                            annotations: {
                                points: payload.oneOffs.map((oneOff) => ({
                                    x: oneOff.x,
                                    y: 0,
                                    marker: {
                                        size: 5,
                                        fillColor: '#F1A51C',
                                        strokeColor: '#111',
                                        strokeWidth: 1.5,
                                    },
                                    label: {
                                        borderColor: '#111',
                                        style: {
                                            color: '#111',
                                            background: '#FCF0D6',
                                            fontWeight: 700,
                                            fontSize: '10px',
                                        },
                                        text: oneOff.label + ' · ' + formatMoney(oneOff.amountCents),
                                    },
                                })),
                            },
                        });
                        this.chart.render();
                    },
                    destroy() {
                        this.chart?.destroy();
                    },
                }"
                x-init="init()"
                class="proj-canvas"
        >
            <div x-ref="canvas"></div>
        </div>
    @endif
</section>
