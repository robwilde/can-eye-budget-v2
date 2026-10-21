import { sankey, sankeyLinkHorizontal } from 'd3-sankey';

const SVG_NS = 'http://www.w3.org/2000/svg';
const CHART_FONT = 'Lato, ui-sans-serif, system-ui, sans-serif';
const NARROW_WIDTH = 560;

const money = (cents) => {
    const formatted = (Math.abs(cents) / 100).toLocaleString('en-AU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return (cents < 0 ? '-$' : '$') + formatted;
};

const compact = (cents) => {
    const dollars = cents / 100;

    return '$' + (dollars >= 1000 ? (dollars / 1000).toFixed(1) + 'k' : Math.round(dollars));
};

const truncate = (text, max) => (text.length > max ? text.slice(0, max - 1) + '…' : text);

const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);

const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const svgEl = (name, attrs = {}, text = null) => {
    const node = document.createElementNS(SVG_NS, name);

    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));

    if (text !== null) {
        node.textContent = text;
    }

    return node;
};

const visibleLabels = (nodes, minGap) => {
    const keep = new Set();
    const byColumn = new Map();

    [...nodes]
        .sort((a, b) => b.value - a.value)
        .forEach((node) => {
            const column = Math.round(node.x0);
            const taken = byColumn.get(column) ?? [];
            const centre = (node.y0 + node.y1) / 2;

            if (taken.every((other) => Math.abs(other - centre) >= minGap)) {
                taken.push(centre);
                byColumn.set(column, taken);
                keep.add(node.index);
            }
        });

    return keep;
};

const linkTint = (link) => (['expense', 'surplus'].includes(link.target.kind) ? link.target.color : link.source.color);

document.addEventListener('alpine:init', () => {
    window.Alpine.data('moneyFlowCarousel', (labels) => ({
        labels,
        index: 0,
        announcement: '',
        observer: null,

        init() {
            const track = this.$refs.track;

            this.observer = new ResizeObserver(() => this.fit());
            Array.from(track.children).forEach((slide) => this.observer.observe(slide));
            this.$watch('index', () => this.$nextTick(() => this.fit()));
            this.$nextTick(() => this.fit());
        },

        destroy() {
            this.observer?.disconnect();
        },

        fit() {
            const track = this.$refs.track;
            const slide = track.children[this.index];

            if (slide) {
                track.style.height = `${slide.offsetHeight}px`;
            }
        },

        go(target) {
            const next = Math.max(0, Math.min(this.labels.length - 1, target));
            const track = this.$refs.track;

            if (next !== this.index) {
                this.index = next;
                this.announce();
            }

            track.scrollTo({ left: next * track.clientWidth, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
            this.$nextTick(() => this.rescueFocus());
        },

        step(delta) {
            this.go(this.index + delta);
        },

        sync() {
            const track = this.$refs.track;

            if (track.clientWidth === 0) {
                return;
            }

            const current = Math.max(0, Math.min(this.labels.length - 1, Math.round(track.scrollLeft / track.clientWidth)));

            if (current !== this.index) {
                this.index = current;
                this.announce();
                this.$nextTick(() => this.rescueFocus());
            }
        },

        announce() {
            this.announcement = `${this.labels[this.index]}, view ${this.index + 1} of ${this.labels.length}`;
        },

        rescueFocus() {
            const focused = document.activeElement;

            if (focused && focused.closest('.mf-slide[inert]') && this.$root.contains(focused)) {
                this.$root.focus({ preventScroll: true });
            }
        },
    }));

    window.Alpine.data('moneySankey', (payload) => ({
        overview: payload.overview,
        drilldowns: payload.drilldowns,
        drillId: null,
        observer: null,
        drawnWidth: 0,

        get graph() {
            return this.drillId === null ? this.overview : this.drilldowns[this.drillId];
        },

        get drilled() {
            return this.drillId !== null;
        },

        get title() {
            if (this.drillId === null) {
                return 'All categories';
            }

            return this.overview.nodes.find((node) => node.id === this.drillId)?.name ?? '';
        },

        get rows() {
            const { nodes, links } = this.graph;

            return links.map((link, position) => ({
                key: `${this.drillId ?? 'overview'}-${position}`,
                from: nodes[link.source].name,
                to: nodes[link.target].name,
                amount: money(link.value),
            }));
        },

        init() {
            this.$nextTick(() => {
                this.draw(true);
                this.observer = new ResizeObserver(() => this.draw());
                this.observer.observe(this.$refs.canvas);
            });
        },

        destroy() {
            this.observer?.disconnect();
        },

        canDrill(node) {
            return this.drillId === null && node.drillable && this.drilldowns[node.id] !== undefined;
        },

        drill(id) {
            this.drillId = id;
            this.draw(true);
            this.$nextTick(() => this.$refs.back?.focus());
        },

        back() {
            const previous = this.drillId;

            this.drillId = null;
            this.draw(true);
            this.$nextTick(() => {
                this.$refs.canvas.querySelector(`[data-node-id="${CSS.escape(previous ?? '')}"]`)?.focus();
            });
        },

        draw(force = false) {
            const canvas = this.$refs.canvas;
            const width = canvas.clientWidth;

            if (! force && width === this.drawnWidth) {
                return;
            }

            this.drawnWidth = width;
            canvas.replaceChildren();

            const graph = this.graph;

            if (width === 0 || ! graph || graph.nodes.length === 0) {
                return;
            }

            const narrow = width < NARROW_WIDTH;
            const height = Math.min(560, Math.max(narrow ? 300 : 280, graph.nodes.length * (narrow ? 26 : 24)));
            const marginLeft = narrow ? 4 : 170;
            const marginRight = narrow ? 4 : 230;
            const nameLimit = narrow ? 14 : 24;

            const layout = sankey()
                .nodeWidth(14)
                .nodePadding(narrow ? 12 : 14)
                .extent([[marginLeft, 6], [width - marginRight, height - 6]]);

            const { nodes, links } = layout({
                nodes: graph.nodes.map((node) => ({ ...node })),
                links: graph.links.map((link) => ({ ...link })),
            });

            const svg = svgEl('svg', {
                viewBox: `0 0 ${width} ${height}`,
                width,
                height,
                class: 'mf-svg',
                role: 'group',
                'aria-label': `Cash flow diagram: ${this.title}`,
            });
            const linkLayer = svgEl('g', { 'aria-hidden': 'true' });
            const nodeLayer = svgEl('g');
            const labelLayer = svgEl('g', { 'aria-hidden': 'true' });

            links.forEach((link) => {
                const path = svgEl('path', {
                    d: sankeyLinkHorizontal()(link),
                    fill: 'none',
                    stroke: linkTint(link),
                    'stroke-width': Math.max(1, link.width),
                    class: 'mf-link',
                });

                path.appendChild(svgEl('title', {}, `${link.source.name} to ${link.target.name}: ${money(link.value)}`));
                linkLayer.appendChild(path);
            });

            const labelled = visibleLabels(nodes, 15);
            const lastColumn = Math.max(...nodes.map((node) => node.depth));

            nodes.forEach((node) => {
                const drillable = this.canDrill(node);
                const group = svgEl('g', { class: drillable ? 'mf-node is-drillable' : 'mf-node', 'data-node-id': node.id });
                const rect = svgEl('rect', {
                    x: node.x0,
                    y: node.y0,
                    width: node.x1 - node.x0,
                    height: Math.max(2, node.y1 - node.y0),
                    rx: 2,
                    fill: node.color,
                });

                group.appendChild(rect);

                if (drillable) {
                    Object.entries({
                        tabindex: '0',
                        role: 'button',
                        'aria-label': `${node.name}, ${money(node.value)}. Show breakdown`,
                    }).forEach(([key, value]) => group.setAttribute(key, value));
                    group.addEventListener('click', () => this.drill(node.id));
                    group.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            this.drill(node.id);
                        }
                    });
                } else {
                    group.appendChild(svgEl('title', {}, `${node.name}: ${money(node.value)}`));
                }

                nodeLayer.appendChild(group);

                const first = node.depth === 0;
                const last = node.depth === lastColumn && ! first;
                const hub = ! first && ! last;

                if (! labelled.has(node.index) || (narrow && hub)) {
                    return;
                }

                const placeLeft = narrow ? last : first;
                const label = svgEl('text', {
                    x: placeLeft ? node.x0 - 6 : node.x1 + 6,
                    y: (node.y0 + node.y1) / 2 + 4,
                    'text-anchor': placeLeft ? 'end' : 'start',
                });

                label.appendChild(document.createTextNode(truncate(node.name, nameLimit) + ' '));
                label.appendChild(svgEl('tspan', { class: 'amt' }, narrow ? compact(node.value) : money(node.value)));
                labelLayer.appendChild(label);
            });

            svg.append(linkLayer, nodeLayer, labelLayer);
            canvas.appendChild(svg);
        },
    }));

    window.Alpine.data('outflowsDonut', (rows, total) => ({
        chart: null,

        init() {
            this.$nextTick(() => {
                this.chart = new window.ApexCharts(this.$refs.donut, {
                    chart: { type: 'donut', height: 280, fontFamily: CHART_FONT, animations: { enabled: ! prefersReducedMotion() } },
                    series: rows.map((row) => row.amount / 100),
                    labels: rows.map((row) => row.name),
                    colors: rows.map((row) => row.color),
                    legend: { show: false },
                    dataLabels: { enabled: false },
                    stroke: { width: 2, colors: ['#fff'] },
                    tooltip: {
                        y: {
                            formatter: (value) => money(Math.round(value * 100)),
                            title: { formatter: (name) => (name ? `${escapeHtml(name)}: ` : '') },
                        },
                    },
                    plotOptions: {
                        pie: {
                            donut: {
                                size: '72%',
                                labels: {
                                    show: true,
                                    name: { fontSize: '13px', fontWeight: 700 },
                                    value: {
                                        fontSize: '22px',
                                        fontWeight: 900,
                                        formatter: (value) => money(Math.round(Number(value) * 100)),
                                    },
                                    total: {
                                        show: true,
                                        showAlways: true,
                                        label: 'Total outflows',
                                        fontSize: '13px',
                                        fontWeight: 700,
                                        formatter: () => money(total),
                                    },
                                },
                            },
                        },
                    },
                });
                this.chart.render();
            });
        },

        destroy() {
            this.chart?.destroy();
        },
    }));
});
