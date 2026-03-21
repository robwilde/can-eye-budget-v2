<div data-testid="spending-over-time">
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        <div class="flex items-center justify-between p-4">
            <flux:heading>Spending Over Time</flux:heading>
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">7 days</flux:select.option>
                <flux:select.option value="30d">30 days</flux:select.option>
                <flux:select.option value="90d">90 days</flux:select.option>
                <flux:select.option value="12m">12 months</flux:select.option>
            </flux:select>
        </div>

        <flux:separator/>

        @if(empty($this->timeSeriesData))
            <div class="p-8 text-center">
                <flux:icon.chart-bar class="mx-auto size-12 text-zinc-400"/>
                <flux:heading size="lg" class="mt-4">No spending data</flux:heading>
                <flux:text class="mt-2">Spending trends will appear here once you have transactions.</flux:text>
            </div>
        @else
            <div class="p-4">
                <div
                        wire:ignore
                        x-data="{
                        chart: null,
                        aggregation: @js($aggregation, JSON_THROW_ON_ERROR),
                        init() {
                            this.chart = new ApexCharts(this.$refs.chart, this.chartOptions(@js($this->timeSeriesData, JSON_THROW_ON_ERROR), this.aggregation))
                            this.chart.render()

                            Livewire.on('spending-over-time-updated', (event) => {
                                this.aggregation = event.aggregation
                                this.chart.updateOptions(this.chartOptions(event.data, event.aggregation))
                            })
                        },
                        tooltipDateFormat(aggregation) {
                            if (aggregation === 'month') return 'MMM yyyy'
                            if (aggregation === 'week') return 'dd MMM yyyy'
                            return 'dd MMM'
                        },
                        chartOptions(data, aggregation) {
                            const isDark = document.documentElement.classList.contains('dark')
                            const textColor = isDark ? '#d4d4d8' : '#3f3f46'

                            return {
                                chart: {
                                    type: 'area',
                                    height: 300,
                                    toolbar: { show: false },
                                    zoom: { enabled: false },
                                },
                                series: [{
                                    name: 'Spending',
                                    data: data.map(item => {
                                        const [year, month, day] = item.date.split('-').map(Number)

                                        return {
                                            x: new Date(year, month - 1, day).getTime(),
                                            y: item.total,
                                        }
                                    }),
                                }],
                                xaxis: {
                                    type: 'datetime',
                                    labels: {
                                        style: { colors: textColor },
                                        datetimeUTC: false,
                                    },
                                },
                                yaxis: {
                                    labels: {
                                        style: { colors: textColor },
                                        formatter: (val) => '$' + (val / 100).toLocaleString('en-AU', { minimumFractionDigits: 0, maximumFractionDigits: 0 }),
                                    },
                                },
                                tooltip: {
                                    x: { format: this.tooltipDateFormat(aggregation) },
                                    y: {
                                        formatter: (val) => '$' + (val / 100).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                                    },
                                },
                                colors: ['#6366F1'],
                                fill: {
                                    type: 'gradient',
                                    gradient: {
                                        shadeIntensity: 1,
                                        opacityFrom: 0.45,
                                        opacityTo: 0.05,
                                        stops: [0, 100],
                                    },
                                },
                                stroke: {
                                    curve: 'smooth',
                                    width: 2,
                                },
                                dataLabels: { enabled: false },
                                grid: {
                                    borderColor: isDark ? '#3f3f46' : '#e5e7eb',
                                    strokeDashArray: 4,
                                },
                                responsive: [{
                                    breakpoint: 480,
                                    options: {
                                        chart: { height: 250 },
                                    },
                                }],
                            }
                        }
                    }"
                >
                    <div x-ref="chart"></div>
                </div>
            </div>
        @endif
    </div>
</div>
