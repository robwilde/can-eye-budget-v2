@props([
    'points',
    'favourable' => true,
    'label' => 'Balance',
])

@php
    $colour = $favourable ? '#18923A' : '#E5484D';
    $payload = array_map(
        static fn (string $day, int $cents): array => ['x' => $day, 'y' => $cents],
        array_keys($points),
        array_values($points),
    );
@endphp

<div
        {{ $attributes }}
        wire:ignore
        wire:key="balance-chart-{{ crc32(json_encode($payload, JSON_THROW_ON_ERROR).$colour) }}"
        x-data="{
            chart: null,
            init() {
                this.$nextTick(() => {
                    if (this.chart || !this.$refs.canvas) return;

                    const formatMoney = (cents) => {
                        const formatted = Math.abs(cents / 100).toLocaleString('en-AU', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                        });
                        return (cents < 0 ? '-$' : '$') + formatted;
                    };

                    this.chart = new ApexCharts(this.$refs.canvas, {
                        chart: {
                            type: 'area',
                            height: 260,
                            toolbar: { show: false },
                            fontFamily: 'Lato, ui-sans-serif, system-ui, sans-serif',
                            zoom: { enabled: false },
                        },
                        series: [{
                            name: @js($label),
                            data: @js($payload, JSON_THROW_ON_ERROR).map((p) => ({ x: new Date(p.x).getTime(), y: p.y })),
                        }],
                        colors: [@js($colour)],
                        stroke: { width: 3, curve: 'smooth' },
                        fill: {
                            type: 'gradient',
                            gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] },
                        },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#DBDEDD', strokeDashArray: 3 },
                        xaxis: {
                            type: 'datetime',
                            labels: {
                                style: { fontWeight: 700, fontSize: '11px' },
                                datetimeFormatter: { year: 'yyyy', month: 'MMM yy', day: 'dd MMM' },
                            },
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
                            shared: false,
                            intersect: false,
                            x: { format: 'dd MMM yyyy' },
                            y: { formatter: (value) => formatMoney(value) },
                        },
                    });
                    this.chart.render();
                });
            },
            destroy() {
                this.chart?.destroy();
            },
        }"
>
    <div x-ref="canvas"></div>
</div>
