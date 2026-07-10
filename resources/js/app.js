import ApexCharts from 'apexcharts';
import FeedbackPlus from 'feedbackplus';

window.ApexCharts = ApexCharts;
window.FeedbackPlus = FeedbackPlus;

const CHART_FONT = 'Lato, ui-sans-serif, system-ui, sans-serif';
const AXIS_INK = '#111';
const GRID_LINE = '#DBDEDD';
const INCOME_GREEN = '#18923A';
const EXPENSE_RED = '#E5484D';
const NET_TEAL = '#0E5E57';

const formatMoney = (cents) => {
    const dollars = Math.abs(cents / 100);
    const formatted = dollars.toLocaleString('en-AU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return (cents < 0 ? '-$' : '$') + formatted;
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('reportCharts', (chart, treemap) => ({
        chart,
        treemap,
        instances: { timeseries: null, expenseTree: null, incomeTree: null },
        stopListening: null,

        init() {
            this.$nextTick(() => this.renderAll());

            this.stopListening = window.Livewire.on('reports:refresh', (payload) => {
                const data = Array.isArray(payload) ? payload[0] : payload;

                if (!data) {
                    return;
                }

                this.chart = data.chart;
                this.treemap = data.treemap;
                this.$nextTick(() => this.renderAll());
            });
        },

        destroy() {
            if (this.stopListening) {
                this.stopListening();
            }

            Object.values(this.instances).forEach((instance) => instance && instance.destroy());
        },

        renderAll() {
            this.renderTimeseries();
            this.renderTreemap('expenseTree', this.treemap.expense, 'No expenses in this period');
            this.renderTreemap('incomeTree', this.treemap.income, 'No income in this period');
        },

        renderTimeseries() {
            const el = this.$refs.timeseries;

            if (!el) {
                return;
            }

            const options = {
                chart: {
                    type: 'line',
                    height: 320,
                    toolbar: { show: false },
                    fontFamily: CHART_FONT,
                    zoom: { enabled: false },
                },
                series: [
                    { name: 'Income', type: 'column', data: this.chart.income },
                    { name: 'Expenses', type: 'column', data: this.chart.expense },
                    { name: 'Net', type: 'line', data: this.chart.net },
                ],
                colors: [INCOME_GREEN, EXPENSE_RED, NET_TEAL],
                stroke: { width: [0, 0, 3], curve: 'smooth' },
                plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
                dataLabels: { enabled: false },
                legend: { show: true, fontWeight: 700, fontFamily: CHART_FONT },
                grid: { borderColor: GRID_LINE, strokeDashArray: 3 },
                xaxis: {
                    categories: this.chart.labels,
                    axisBorder: { color: AXIS_INK },
                    axisTicks: { color: AXIS_INK },
                    labels: { style: { fontWeight: 700, fontSize: '11px' } },
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
            };

            if (this.instances.timeseries) {
                this.instances.timeseries.updateOptions(options, true, true);

                return;
            }

            this.instances.timeseries = new ApexCharts(el, options);
            this.instances.timeseries.render();
        },

        renderTreemap(ref, nodes, emptyMessage) {
            const el = this.$refs[ref];

            if (!el) {
                return;
            }

            if (this.instances[ref]) {
                this.instances[ref].destroy();
                this.instances[ref] = null;
            }

            if (!nodes || nodes.length === 0) {
                el.innerHTML = `<div class="report-empty">${emptyMessage}</div>`;

                return;
            }

            el.innerHTML = '';

            const options = {
                chart: {
                    type: 'treemap',
                    height: 280,
                    toolbar: { show: false },
                    fontFamily: CHART_FONT,
                },
                series: [{ data: nodes.map((node) => ({ x: node.x, y: node.y })) }],
                colors: nodes.map((node) => node.color),
                plotOptions: { treemap: { distributed: true, enableShades: false } },
                dataLabels: {
                    enabled: true,
                    style: { fontWeight: 700, fontSize: '11px' },
                    formatter: (text) => text,
                },
                legend: { show: false },
                tooltip: { y: { formatter: (value) => formatMoney(value) } },
            };

            this.instances[ref] = new ApexCharts(el, options);
            this.instances[ref].render();
        },
    }));
});
