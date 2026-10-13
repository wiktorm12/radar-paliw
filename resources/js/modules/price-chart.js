import { BREAKPOINTS, isBreakpointUp } from '../utils/dom';

const SERIES_COLORS = {
    pb95: '#2a78d6',
    pb98: '#eb6834',
    on: '#1baf7a',
    lpg: '#eda100',
};

const INK = {
    primary: '#0b0b0b',
    secondary: '#52514e',
    muted: '#898781',
    grid: '#e1e0d9',
    axis: '#c3c2b7',
    surface: '#ffffff',
};

const SVG_NS = 'http://www.w3.org/2000/svg';

const formatPrice = (value) => value.toFixed(2).replace('.', ',');

const formatDate = (isoDate, withYear = false) => {
    const [year, month, day] = isoDate.split('-');

    return withYear ? `${Number(day)}.${month}.${year}` : `${Number(day)}.${month}`;
};

const createSvgElement = (name, attributes = {}) => {
    const element = document.createElementNS(SVG_NS, name);

    Object.entries(attributes).forEach(([key, value]) => element.setAttribute(key, value));

    return element;
};

class PriceChart {
    constructor(chartElement) {
        this.chartElement = chartElement;
        this.data = JSON.parse(chartElement.querySelector('[data-price-chart-data]').textContent);
        this.activeIndex = null;

        if (!this.data.dates.length) {
            return;
        }

        this.render();
        this.initEvents();
    }

    initEvents() {
        new ResizeObserver(() => this.render()).observe(this.chartElement);
    }

    render() {
        const width = this.chartElement.clientWidth - this.horizontalPadding();

        if (width <= 0 || width === this.renderedWidth) {
            return;
        }

        this.renderedWidth = width;
        this.chartElement.replaceChildren(this.renderLegend(), this.renderPlot(width), this.renderTooltip());
    }

    horizontalPadding() {
        const style = getComputedStyle(this.chartElement);

        return parseFloat(style.paddingLeft) + parseFloat(style.paddingRight);
    }

    renderLegend() {
        const legendElement = document.createElement('ul');

        legendElement.className = 'mb-4 flex flex-wrap gap-x-5 gap-y-2 text-sm text-zinc-700';

        this.data.series.forEach((series) => {
            const itemElement = document.createElement('li');
            const swatchElement = document.createElement('span');

            itemElement.className = 'flex items-center gap-2';
            swatchElement.className = 'inline-block h-0.5 w-4 rounded-full';
            swatchElement.style.backgroundColor = SERIES_COLORS[series.key];
            itemElement.append(swatchElement, series.label);
            legendElement.append(itemElement);
        });

        return legendElement;
    }

    renderPlot(width) {
        const height = isBreakpointUp(BREAKPOINTS.MD) ? 340 : 260;
        const margin = { top: 12, right: isBreakpointUp(BREAKPOINTS.SM) ? 104 : 56, bottom: 28, left: 44 };
        const innerWidth = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;
        const values = this.data.series.flatMap((series) => series.values).filter((value) => value !== null);
        const yMin = Math.floor((Math.min(...values) - 0.05) * 10) / 10;
        const yMax = Math.ceil((Math.max(...values) + 0.05) * 10) / 10;
        const count = this.data.dates.length;

        this.x = (index) => margin.left + (count === 1 ? innerWidth / 2 : (index / (count - 1)) * innerWidth);
        this.y = (value) => margin.top + innerHeight - ((value - yMin) / (yMax - yMin)) * innerHeight;
        this.margin = margin;
        this.innerHeight = innerHeight;

        const svgElement = createSvgElement('svg', {
            width,
            height,
            viewBox: `0 0 ${width} ${height}`,
            role: 'img',
            'aria-label': 'Wykres liniowy cen maksymalnych paliw w programie CPN. Pełne dane w tabeli poniżej.',
            class: 'block overflow-visible',
        });

        svgElement.append(
            this.renderGrid(yMin, yMax, innerWidth),
            this.renderXAxis(innerWidth),
            ...this.data.series.map((series) => this.renderLine(series)),
            this.renderEndLabels(),
        );

        this.crosshairElement = createSvgElement('line', {
            y1: margin.top,
            y2: margin.top + innerHeight,
            stroke: INK.axis,
            'stroke-width': 1,
            visibility: 'hidden',
        });
        this.hoverGroupElement = createSvgElement('g');

        const hitAreaElement = createSvgElement('rect', {
            x: margin.left - 8,
            y: margin.top,
            width: innerWidth + 16,
            height: innerHeight,
            fill: 'transparent',
        });

        hitAreaElement.addEventListener('pointermove', (event) => this.onPointerMove(event, svgElement));
        hitAreaElement.addEventListener('pointerleave', () => this.hideHover());

        svgElement.append(this.crosshairElement, this.hoverGroupElement, hitAreaElement);

        return svgElement;
    }

    renderGrid(yMin, yMax, innerWidth) {
        const groupElement = createSvgElement('g');
        const steps = 4;

        for (let step = 0; step <= steps; step += 1) {
            const value = yMin + ((yMax - yMin) * step) / steps;
            const y = this.y(value);

            groupElement.append(
                createSvgElement('line', {
                    x1: this.margin.left,
                    x2: this.margin.left + innerWidth,
                    y1: y,
                    y2: y,
                    stroke: step === 0 ? INK.axis : INK.grid,
                    'stroke-width': 1,
                }),
            );

            const labelElement = createSvgElement('text', {
                x: this.margin.left - 8,
                y: y + 4,
                'text-anchor': 'end',
                'font-size': 12,
                fill: INK.muted,
                style: 'font-variant-numeric: tabular-nums',
            });

            labelElement.textContent = formatPrice(value);
            groupElement.append(labelElement);
        }

        return groupElement;
    }

    renderXAxis(innerWidth) {
        const groupElement = createSvgElement('g');
        const count = this.data.dates.length;
        const maxLabels = Math.max(2, Math.floor(innerWidth / 56));
        const every = Math.ceil(count / maxLabels);

        this.data.dates.forEach((date, index) => {
            if (index % every !== 0 && index !== count - 1) {
                return;
            }

            const labelElement = createSvgElement('text', {
                x: this.x(index),
                y: this.margin.top + this.innerHeight + 20,
                'text-anchor': 'middle',
                'font-size': 12,
                fill: INK.muted,
            });

            labelElement.textContent = formatDate(date);
            groupElement.append(labelElement);
        });

        return groupElement;
    }

    renderLine(series) {
        const groupElement = createSvgElement('g');
        const points = series.values
            .map((value, index) => (value === null ? null : [this.x(index), this.y(value)]))
            .filter(Boolean);

        groupElement.append(
            createSvgElement('polyline', {
                points: points.map((point) => point.join(',')).join(' '),
                fill: 'none',
                stroke: SERIES_COLORS[series.key],
                'stroke-width': 2,
                'stroke-linejoin': 'round',
                'stroke-linecap': 'round',
            }),
        );

        if (points.length <= 31) {
            points.forEach(([x, y]) => {
                groupElement.append(
                    createSvgElement('circle', {
                        cx: x,
                        cy: y,
                        r: 3,
                        fill: SERIES_COLORS[series.key],
                        stroke: INK.surface,
                        'stroke-width': 1.5,
                    }),
                );
            });
        }

        return groupElement;
    }

    renderEndLabels() {
        const groupElement = createSvgElement('g');
        const showLabelNames = isBreakpointUp(BREAKPOINTS.SM);
        const labels = this.data.series
            .map((series) => {
                const lastIndex = series.values.findLastIndex((value) => value !== null);

                return lastIndex === -1 ? null : { series, value: series.values[lastIndex], x: this.x(lastIndex), y: this.y(series.values[lastIndex]) };
            })
            .filter(Boolean)
            .sort((a, b) => a.y - b.y);

        labels.forEach((label, index) => {
            if (index > 0 && label.y - labels[index - 1].y < 16) {
                label.y = labels[index - 1].y + 16;
            }

            const textElement = createSvgElement('text', {
                x: label.x + 10,
                y: label.y + 4,
                'font-size': 12,
                fill: INK.secondary,
                style: 'font-variant-numeric: tabular-nums',
            });

            textElement.textContent = showLabelNames ? `${label.series.label.replace('Olej napędowy', 'ON').replace('Benzyna ', 'Pb')} ${formatPrice(label.value)}` : formatPrice(label.value);
            groupElement.append(textElement);
        });

        return groupElement;
    }

    renderTooltip() {
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'pointer-events-none absolute z-10 hidden min-w-40 rounded-lg border border-zinc-200 bg-white p-3 text-sm shadow-lg';
        this.chartElement.style.position = 'relative';

        return this.tooltipElement;
    }

    onPointerMove(event, svgElement) {
        const bounds = svgElement.getBoundingClientRect();
        const pointerX = event.clientX - bounds.left;
        const count = this.data.dates.length;
        const index = Math.max(0, Math.min(count - 1, Math.round(((pointerX - this.margin.left) / (this.x(count - 1) - this.margin.left || 1)) * (count - 1))));

        if (index !== this.activeIndex) {
            this.activeIndex = index;
            this.showHover(index, svgElement);
        }
    }

    showHover(index, svgElement) {
        const x = this.x(index);

        this.crosshairElement.setAttribute('x1', x);
        this.crosshairElement.setAttribute('x2', x);
        this.crosshairElement.setAttribute('visibility', 'visible');
        this.hoverGroupElement.replaceChildren();

        const rows = this.data.series
            .filter((series) => series.values[index] !== null)
            .map((series) => ({ series, value: series.values[index] }))
            .sort((a, b) => b.value - a.value);

        rows.forEach(({ series, value }) => {
            this.hoverGroupElement.append(
                createSvgElement('circle', {
                    cx: x,
                    cy: this.y(value),
                    r: 5,
                    fill: SERIES_COLORS[series.key],
                    stroke: INK.surface,
                    'stroke-width': 2,
                }),
            );
        });

        this.tooltipElement.replaceChildren();

        const titleElement = document.createElement('p');

        titleElement.className = 'mb-2 font-semibold text-zinc-900';
        titleElement.textContent = formatDate(this.data.dates[index], true);
        this.tooltipElement.append(titleElement);

        rows.forEach(({ series, value }) => {
            const rowElement = document.createElement('p');
            const swatchElement = document.createElement('span');
            const valueElement = document.createElement('span');

            rowElement.className = 'flex items-center gap-2 text-zinc-700';
            swatchElement.className = 'inline-block size-2 rounded-full';
            swatchElement.style.backgroundColor = SERIES_COLORS[series.key];
            valueElement.className = 'ml-auto pl-4 font-semibold text-zinc-900 tabular-nums';
            valueElement.textContent = `${formatPrice(value)} zł`;
            rowElement.append(swatchElement, series.label, valueElement);
            this.tooltipElement.append(rowElement);
        });

        const chartBounds = this.chartElement.getBoundingClientRect();
        const svgBounds = svgElement.getBoundingClientRect();
        const left = svgBounds.left - chartBounds.left + x;

        this.tooltipElement.classList.remove('hidden');

        const tooltipWidth = this.tooltipElement.offsetWidth;
        const placeLeft = left + tooltipWidth + 16 > chartBounds.width;

        this.tooltipElement.style.left = `${placeLeft ? left - tooltipWidth - 12 : left + 12}px`;
        this.tooltipElement.style.top = `${svgBounds.top - chartBounds.top + this.margin.top}px`;
    }

    hideHover() {
        this.activeIndex = null;
        this.crosshairElement.setAttribute('visibility', 'hidden');
        this.hoverGroupElement.replaceChildren();
        this.tooltipElement.classList.add('hidden');
    }
}

class PriceChartModule {
    init() {
        document.querySelectorAll('[data-price-chart]').forEach((chartElement) => new PriceChart(chartElement));
    }
}

export default new PriceChartModule();
