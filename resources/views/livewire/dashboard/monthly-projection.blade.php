<section class="monthly-projection">
    @if (! $this->hasPrimaryAccount)
        <x-cib.empty-state
                icon="banknotes"
                title="Set your primary account to see your buffer projection"
                description="The 12-month balance line uses your primary account's current cash balance as the starting point."
        >
            <x-slot:action>
                <a class="link" href="{{ route('accounts') }}">
                    Configure primary account →
                </a>
            </x-slot:action>
        </x-cib.empty-state>
    @else
        <div
                wire:ignore
                x-data="{
                    chart: null,
                    init() {
                        this.$nextTick(() => this.renderChart());
                    },
                    renderChart() {
                        if (this.chart || !this.$refs.canvas) return;
                        const payload = @js($this->chartPayload, JSON_THROW_ON_ERROR);

                        const formatMoney = (cents) => {
                            const dollars = Math.abs(cents / 100);
                            const formatted = dollars.toLocaleString('en-AU', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2,
                            });
                            return (cents < 0 ? '-$' : '$') + formatted;
                        };

                        const series = [{
                            name: 'Projected balance',
                            data: payload.points.map(p => ({
                                x: new Date(p.x).getTime(),
                                y: p.y,
                            })),
                        }];

                        const xaxisAnnotations = [];
                        if (payload.firstNegative !== null) {
                            xaxisAnnotations.push({
                                x: new Date(payload.firstNegative).getTime(),
                                borderColor: '#E5484D',
                                strokeDashArray: 4,
                                label: {
                                    text: 'First negative balance',
                                    borderColor: '#E5484D',
                                    style: {
                                        color: '#fff',
                                        background: '#E5484D',
                                        fontWeight: 700,
                                        fontSize: '10px',
                                    },
                                },
                            });
                        }

                        this.chart = new ApexCharts(this.$refs.canvas, {
                            chart: {
                                type: 'area',
                                height: 320,
                                toolbar: { show: false },
                                fontFamily: 'Lato, ui-sans-serif, system-ui, sans-serif',
                                zoom: { enabled: false },
                            },
                            series: series,
                            colors: ['#0E5E57'],
                            stroke: {
                                width: 3,
                                curve: 'smooth',
                            },
                            fill: {
                                type: 'gradient',
                                gradient: {
                                    shadeIntensity: 1,
                                    opacityFrom: 0.4,
                                    opacityTo: 0.05,
                                    stops: [0, 100],
                                },
                            },
                            dataLabels: { enabled: false },
                            legend: { show: false },
                            grid: {
                                borderColor: '#DBDEDD',
                                strokeDashArray: 3,
                            },
                            xaxis: {
                                type: 'datetime',
                                labels: {
                                    style: { fontWeight: 700, fontSize: '11px' },
                                    datetimeFormatter: {
                                        year: 'yyyy',
                                        month: 'MMM yy',
                                        day: 'dd MMM',
                                    },
                                },
                                axisBorder: { color: '#111' },
                                axisTicks: { color: '#111' },
                            },
                            yaxis: {
                                labels: {
                                    formatter: (value) => formatMoney(value),
                                    style: { fontWeight: 700, fontSize: '10px' },
                                },
                                title: {
                                    text: 'Projected balance',
                                    style: { fontWeight: 700, fontSize: '10px', color: '#0E5E57' },
                                },
                            },
                            markers: {
                                size: 4,
                                strokeColors: '#0E5E57',
                                strokeWidth: 2,
                                fillOpacity: 1,
                                hover: { sizeOffset: 2 },
                            },
                            tooltip: {
                                shared: false,
                                intersect: false,
                                x: { format: 'dd MMM yyyy' },
                                y: { formatter: (value) => formatMoney(value) },
                            },
                            annotations: {
                                yaxis: [{
                                    y: 0,
                                    borderColor: '#E5484D',
                                    strokeDashArray: 4,
                                    label: {
                                        text: 'Zero',
                                        borderColor: '#E5484D',
                                        style: {
                                            color: '#fff',
                                            background: '#E5484D',
                                            fontWeight: 700,
                                            fontSize: '10px',
                                        },
                                    },
                                }],
                                xaxis: xaxisAnnotations,
                            },
                        });
                        this.chart.render();
                    },
                    destroy() {
                        this.chart?.destroy();
                    },
                }"
                class="proj-canvas"
        >
            <div x-ref="canvas"></div>
        </div>
    @endif
</section>
