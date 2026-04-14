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
                        rawData: @js($this->timeSeriesData, JSON_THROW_ON_ERROR),
                        aggregation: @js($aggregation, JSON_THROW_ON_ERROR),
                        cleanupListener: null,
                        init() {
                            this.chart = new ApexCharts(this.$refs.chart, this.chartOptions(this.rawData, this.aggregation));
                            this.chart.render();

                            this.cleanupListener = Livewire.on('spending-over-time-updated', (event) => {
                                if (!this.$refs.chart) return;
                                this.rawData = event.data;
                                this.aggregation = event.aggregation;
                                this.chart.updateOptions(this.chartOptions(event.data, event.aggregation));
                            });
                        },
                        destroy() {
                            this.cleanupListener?.();
                            this.chart?.destroy();
                        },
                        escapeHtml(str) {
                            var div = document.createElement('div');
                            div.textContent = str;
                            return div.innerHTML;
                        },
                        formatMoney(cents) {
                            var isNegative = cents < 0;
                            var abs = Math.abs(cents);
                            var formatted = '$' + (abs / 100).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            return isNegative ? '-' + formatted : formatted;
                        },
                        tooltipDate(dateStr, aggregation) {
                            var [year, month, day] = dateStr.split('-').map(Number);
                            var date = new Date(year, month - 1, day);
                            if (aggregation === 'month') return date.toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
                            if (aggregation === 'week') return 'Week of ' + date.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
                            return date.toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });
                        },
                        chartOptions(data, aggregation) {
                            var isDark = document.documentElement.classList.contains('dark');
                            var textColor = isDark ? '#d4d4d8' : '#3f3f46';
                            var self = this;

                            return {
                                chart: {
                                    type: 'area',
                                    height: 300,
                                    toolbar: { show: false },
                                    zoom: { enabled: false },
                                },
                                series: [{
                                    name: 'Net Spending',
                                    data: data.map(item => {
                                        var [year, month, day] = item.date.split('-').map(Number);

                                        return {
                                            x: new Date(year, month - 1, day).getTime(),
                                            y: item.total,
                                        };
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
                                    custom: function({ dataPointIndex }) {
                                        var point = self.rawData[dataPointIndex];
                                        if (!point) return '';

                                        var bg = isDark ? 'background:#27272a;color:#d4d4d8;' : 'background:#fff;color:#3f3f46;';
                                        var bc = isDark ? '#3f3f46' : '#e5e7eb';
                                        var html = '<div style=\'' + bg + 'border:1px solid ' + bc + ';border-radius:8px;padding:10px 12px;font-size:13px;min-width:180px;\'>';
                                        html += '<div style=\'font-weight:600;margin-bottom:6px;\'>' + self.tooltipDate(point.date, self.aggregation) + '</div>';

                                        if (point.accounts && point.accounts.length > 0) {
                                            point.accounts.forEach(function(acc) {
                                                html += '<div style=\'display:flex;justify-content:space-between;gap:16px;padding:1px 0;\'>';
                                                html += '<span style=\'opacity:0.7;\'>' + self.escapeHtml(acc.name) + '</span>';
                                                html += '<span style=\'font-weight:500;font-variant-numeric:tabular-nums;\'>' + self.formatMoney(acc.total) + '</span>';
                                                html += '</div>';
                                            });
                                            html += '<div style=\'border-top:1px solid ' + bc + ';margin:4px 0;\'></div>';
                                        }

                                        html += '<div style=\'display:flex;justify-content:space-between;gap:16px;font-weight:600;\'>';
                                        html += '<span>Net</span>';
                                        html += '<span style=\'font-variant-numeric:tabular-nums;\'>' + self.formatMoney(point.total) + '</span>';
                                        html += '</div></div>';
                                        return html;
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
