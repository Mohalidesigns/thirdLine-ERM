/*
| Widget hydration entry point.
|
| The Blade side renders every widget as:
|
|   <div data-widget data-widget-type="donut">
|     <script type="application/json" data-widget-payload>{envelope}</script>
|     <div data-widget-mount></div>
|   </div>
|
| plus, inside server-rendered kpi tiles, a <canvas data-widget-sparkline>.
| This module finds both, parses the payload, and hands the mount to the
| matching renderer from charts.js (Chart.js) or the DOM renderers below
| (treemap, network). Table-like types are fully server-rendered and never
| reach here.
|
| Re-render paths: initial load, 'livewire:navigated', Livewire DOM morphs
| (MutationObserver), an explicit 'widget:rerender' event, and colour-scheme
| changes (charts are destroyed and rebuilt reading the other theme's
| tokens). A malformed payload leaves the mount empty — a broken widget must
| never take the page down.
*/

import { chartRenderers, renderSparkline } from './charts';
import { readTokens, alpha, isDarkColor, fmtNum } from './theme';

const instances = new WeakMap();

/* ------------------------------------------------------- DOM renderers */

/* Squarified-ish treemap: slice-and-dice alternating by depth is enough for
   a dashboard tile; area stays proportional, labels clamp. */
function treemap(mount, data, env, t) {
    const tiles = (data.tiles || []).filter((x) => +x.value > 0).sort((a, b) => b.value - a.value);
    if (tiles.length === 0) return null;

    const total = tiles.reduce((s, x) => s + +x.value, 0);
    const box = document.createElement('div');
    box.className = 'widget-treemap';

    // Lay out with CSS flex rows: chunk tiles into rows of ~sqrt(n) so areas
    // stay readable without a full squarify pass.
    const perRow = Math.max(1, Math.round(Math.sqrt(tiles.length)));

    for (let i = 0; i < tiles.length; i += perRow) {
        const rowTiles = tiles.slice(i, i + perRow);
        const rowTotal = rowTiles.reduce((s, x) => s + +x.value, 0);
        const row = document.createElement('div');
        row.className = 'widget-treemap-row';
        row.style.flexGrow = String(rowTotal / total);

        rowTiles.forEach((tile, j) => {
            const cell = document.createElement('div');
            const color = t.sequential[Math.min(t.sequential.length - 1, i + j < 2 ? 4 : i + j < 5 ? 3 : 2)];
            cell.className = 'widget-treemap-tile';
            cell.style.flexGrow = String(+tile.value / rowTotal);
            cell.style.backgroundColor = color;
            cell.style.color = isDarkColor(color) ? '#f7fafc' : '#1a202c';
            cell.title = `${tile.label}: ${fmtNum(tile.value)}`;

            const label = document.createElement('span');
            label.className = 'widget-treemap-label';
            label.textContent = tile.label;
            const value = document.createElement('span');
            value.className = 'widget-treemap-value';
            value.textContent = fmtNum(tile.value);

            cell.append(label, value);
            row.appendChild(cell);
        });

        box.appendChild(row);
    }

    mount.appendChild(box);
    return null;
}

/* Radial neighbourhood graph in plain SVG: anchor centred, first hop on an
   inner ring, everything else outer. Peripheral vision, not an explorer. */
function network(mount, data, env, t) {
    const nodes = data.nodes || [];
    const edges = data.edges || [];
    if (nodes.length <= 1) return null;

    const W = mount.clientWidth || 480;
    const H = Math.max(mount.clientHeight || 0, 260);
    const cx = W / 2;
    const cy = H / 2;

    const anchor = nodes.find((n) => n.is_anchor) || nodes[0];
    const firstHop = new Set();
    edges.forEach((e) => {
        if (e.from === anchor.id) firstHop.add(e.to);
        if (e.to === anchor.id) firstHop.add(e.from);
    });

    const inner = nodes.filter((n) => firstHop.has(n.id));
    const outer = nodes.filter((n) => n.id !== anchor.id && !firstHop.has(n.id));

    const pos = new Map();
    pos.set(anchor.id, { x: cx, y: cy });

    const place = (list, radius) => {
        list.forEach((n, i) => {
            const angle = (2 * Math.PI * i) / Math.max(1, list.length) - Math.PI / 2;
            pos.set(n.id, { x: cx + radius * Math.cos(angle), y: cy + radius * Math.sin(angle) });
        });
    };
    place(inner, Math.min(W, H) * 0.28);
    place(outer, Math.min(W, H) * 0.44);

    const svgNS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('class', 'widget-network');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', `Relationship network around ${anchor.label}`);

    edges.forEach((e) => {
        const a = pos.get(e.from);
        const b = pos.get(e.to);
        if (!a || !b) return;
        const line = document.createElementNS(svgNS, 'line');
        line.setAttribute('x1', a.x); line.setAttribute('y1', a.y);
        line.setAttribute('x2', b.x); line.setAttribute('y2', b.y);
        line.setAttribute('stroke', t.grid);
        line.setAttribute('stroke-width', '1');
        svg.appendChild(line);
        const title = document.createElementNS(svgNS, 'title');
        title.textContent = e.code;
        line.appendChild(title);
    });

    nodes.forEach((n) => {
        const p = pos.get(n.id);
        if (!p) return;
        const g = document.createElementNS(svgNS, 'g');

        const circle = document.createElementNS(svgNS, 'circle');
        circle.setAttribute('cx', p.x); circle.setAttribute('cy', p.y);
        circle.setAttribute('r', n.is_anchor ? 14 : 8);
        circle.setAttribute('fill', n.is_anchor ? t.series[0] : alpha(t.series[0], 0.35));
        circle.setAttribute('stroke', t.series[0]);
        g.appendChild(circle);

        const title = document.createElementNS(svgNS, 'title');
        title.textContent = `${n.label}${n.type ? ` (${n.type})` : ''}`;
        g.appendChild(title);

        const text = document.createElementNS(svgNS, 'text');
        text.setAttribute('x', p.x);
        text.setAttribute('y', p.y + (n.is_anchor ? 28 : 20));
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('class', 'widget-network-label');
        text.setAttribute('fill', t.inkSecondary);
        text.textContent = n.label.length > 18 ? n.label.slice(0, 17) + '…' : n.label;
        g.appendChild(text);

        svg.appendChild(g);
    });

    mount.appendChild(svg);
    return null;
}

const domRenderers = { treemap, network };

/* ------------------------------------------------------------ hydration */

function destroyInstance(el) {
    const chart = instances.get(el);
    if (chart && typeof chart.destroy === 'function') chart.destroy();
    instances.delete(el);
}

function hydrateWidget(el, tokens) {
    const type = el.dataset.widgetType;
    const mount = el.querySelector('[data-widget-mount]');
    const script = el.querySelector('script[type="application/json"][data-widget-payload]');
    if (!mount || !script) return;

    let envelope;
    try {
        envelope = JSON.parse(script.textContent);
    } catch {
        return;
    }
    if (!envelope || envelope.state !== 'ok') return;

    destroyInstance(el);
    mount.replaceChildren();

    const renderer = chartRenderers[type] || domRenderers[type];
    if (!renderer) return;

    try {
        const chart = renderer(mount, envelope.data || {}, envelope, tokens);
        if (chart) instances.set(el, chart);

        // A renderer that declined to draw (empty payload) leaves the mount
        // empty; say so rather than showing a silent blank panel. "No data
        // in scope" is an answer, not a failure.
        if (!mount.hasChildNodes()) {
            const note = document.createElement('p');
            note.className = 'widget-empty-note';
            note.textContent = 'No data in scope';
            mount.appendChild(note);
        }
    } catch (e) {
        mount.replaceChildren(); // broken widget stays an empty panel
        if (import.meta.env?.DEV) console.warn(`widget[${type}]`, e);
    }
}

function hydrateAll(root = document) {
    const tokens = readTokens();
    root.querySelectorAll('[data-widget]').forEach((el) => hydrateWidget(el, tokens));
    root.querySelectorAll('canvas[data-widget-sparkline]').forEach((c) => {
        try {
            renderSparkline(c, tokens);
        } catch { /* sparkline is decoration; the number already rendered */ }
    });
}

let observer = null;

export function initWidgets() {
    hydrateAll();

    document.addEventListener('widget:rerender', (e) => {
        const host = e.target instanceof Element ? e.target.closest('[data-widget]') : null;
        if (host) hydrateWidget(host, readTokens());
        else hydrateAll();
    });

    document.addEventListener('livewire:navigated', () => hydrateAll());

    // Livewire morphs swap widget subtrees without any event we can target;
    // watch for [data-widget] arrivals and hydrate just those.
    if (!observer) {
        observer = new MutationObserver((mutations) => {
            const tokens = readTokens();
            for (const m of mutations) {
                m.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) return;
                    if (node.matches?.('[data-widget]')) hydrateWidget(node, tokens);
                    node.querySelectorAll?.('[data-widget]').forEach((el) => hydrateWidget(el, tokens));
                    node.querySelectorAll?.('canvas[data-widget-sparkline]').forEach((c) => {
                        try { renderSparkline(c, tokens); } catch { /* noop */ }
                    });
                });
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => hydrateAll());
}
