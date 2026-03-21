<div>
    <div class="rounded-xl border border-neutral-200 dark:border-neutral-700">
        <div class="flex items-center justify-between p-4">
            <flux:heading>Spending by Category</flux:heading>
            <flux:select wire:model.live="period" size="sm" class="w-auto">
                <flux:select.option value="7d">7 days</flux:select.option>
                <flux:select.option value="30d">30 days</flux:select.option>
                <flux:select.option value="90d">90 days</flux:select.option>
                <flux:select.option value="12m">12 months</flux:select.option>
            </flux:select>
        </div>

        <flux:separator/>

        @if(empty($this->categoryData))
            <div class="p-8 text-center">
                <flux:icon.chart-pie class="mx-auto size-12 text-zinc-400"/>
                <flux:heading size="lg" class="mt-4">No spending data</flux:heading>
                <flux:text class="mt-2">Transactions will appear here once you have spending activity.</flux:text>
            </div>
        @else
            <div class="p-4">
                <div
                        wire:ignore
                        x-data="{
                        chart: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.chart, this.chartOptions(@js($this->categoryData, JSON_THROW_ON_ERROR)))
                            this.chart.render()

                            Livewire.on('chart-updated', (event) => {
                                this.chart.updateOptions(this.chartOptions(event.data))
                            })
                        },
                        chartOptions(data) {
                            return {
                                chart: {
                                    type: 'donut',
                                    height: 300,
                                    events: {
                                        dataPointSelection: (event, chartContext, config) => {
                                            const categoryId = data[config.dataPointIndex]?.category_id
                                            const period = this.$wire?.period ?? @js($this->period, JSON_THROW_ON_ERROR)
                                            const baseUrl = @js(route('transactions'), JSON_THROW_ON_ERROR)
                                            const params = new URLSearchParams({ category: categoryId ?? '', period: period })
                                            window.location.href = baseUrl + '?' + params.toString()
                                        }
                                    }
                                },
                                series: data.map(item => item.total),
                                labels: data.map(item => item.name),
                                colors: data.map(item => item.color),
                                tooltip: {
                                    y: {
                                        formatter: (val) => '$' + (val / 100).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                                    }
                                },
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        colors: document.documentElement.classList.contains('dark') ? '#d4d4d8' : '#3f3f46'
                                    }
                                },
                                plotOptions: {
                                    pie: {
                                        donut: {
                                            labels: {
                                                show: true,
                                                total: {
                                                    show: true,
                                                    label: 'Total',
                                                    formatter: (w) => {
                                                        const total = w.globals.seriesTotals.reduce((a, b) => a + b, 0)
                                                        return '$' + (total / 100).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                                                    },
                                                    color: document.documentElement.classList.contains('dark') ? '#d4d4d8' : '#3f3f46'
                                                },
                                                value: {
                                                    color: document.documentElement.classList.contains('dark') ? '#d4d4d8' : '#3f3f46'
                                                }
                                            }
                                        }
                                    }
                                },
                                dataLabels: { enabled: false },
                                stroke: { width: 2 },
                                responsive: [{
                                    breakpoint: 480,
                                    options: {
                                        chart: { height: 250 },
                                        legend: { position: 'bottom' }
                                    }
                                }]
                            }
                        }
                    }"
                >
                    <div x-ref="chart"></div>
                </div>

                <flux:separator class="my-4"/>

                <div class="space-y-2">
                    @foreach($this->categoryData as $item)
                        <a
                                href="{{ route('transactions', ['category' => $item['category_id'], 'period' => $period]) }}"
                                class="flex items-center justify-between rounded-lg px-3 py-2 transition hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                wire:key="category-{{ $item['category_id'] ?? 'uncategorized' }}"
                        >
                            <div class="flex items-center gap-2">
                                <span class="inline-block size-3 rounded-full" style="background-color: {{ $item['color'] }}"></span>
                                <flux:text>{{ $item['name'] }}</flux:text>
                            </div>
                            <flux:text class="tabular-nums font-medium">{{ $formatMoney($item['total']) }}</flux:text>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
