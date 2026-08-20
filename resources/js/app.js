import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import Chart from 'chart.js/auto';

/*
| Alpine and Chart.js used to arrive from cdn.jsdelivr.net on every page load,
| which meant a deployment could not honour an on-premise data-residency claim:
| each request told a third party who was using the platform and when.
|
| Both are bundled now. They stay on `window` because the Blade views call them
| as globals — `x-data` attributes for Alpine, `new Chart(...)` in inline
| <script> blocks for Chart.js.
|
| ALPINE COMES FROM LIVEWIRE, not from the alpinejs package directly. Livewire 3
| ships its own Alpine, and two copies on one page fight over the same
| directives: every existing x-data block would break the moment a Livewire
| component appeared anywhere in the layout. This is Livewire's documented
| manual-bundling setup — one Alpine, started by Livewire.start(), with the
| layout emitting @livewireScriptConfig instead of @livewireScripts.
*/
window.Alpine = Alpine;
window.Chart = Chart;

Livewire.start();

/*
| SPA navigation (wire:navigate) support.
|
| After a wire:navigate visit the document is never re-parsed, so
| DOMContentLoaded fires exactly once per browser session. Inline page
| scripts therefore register through window.onPageReady instead of
| DOMContentLoaded. The canonical definition is an inline head script in
| layouts/app.blade.php (it must exist before body scripts parse); this
| guarded copy covers pages that load the bundle without that layout,
| e.g. the auth screens.
*/
window.onPageReady ??= (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

/*
| The WP-08 widget engine hydrates here. initWidgets() finds [data-widget]
| panels and hands each its JSON payload; initDashboardBuilder() wires
| GridStack on the builder surface.
|
| Both were removed when Business HQ and the dashboard builder were retired —
| at that point the two calls only ever walked an empty DOM while their
| imports pulled GridStack and every chart resolver into the bundle for every
| page in the product. WP-12 restores the surfaces, so they are back.
|
| The imports are dynamic on purpose. GridStack plus the chart resolvers are
| the single largest thing in this bundle and exactly two screens need them;
| a static import would put that cost back on every pageview, which was a fair
| half of the argument for retiring them in the first place. The DOM probe
| below costs one querySelector.
*/
window.onPageReady(() => {
    const needsWidgets = document.querySelector('[data-widget]') !== null;
    const needsBuilder = document.querySelector('[data-dashboard-builder]') !== null;

    if (!needsWidgets && !needsBuilder) {
        return;
    }

    // Two chunks, not one: the builder drags in GridStack and its stylesheet,
    // which the read-only HQ page has no use for.
    if (needsWidgets) {
        import('./widgets/index.js')
            .then(({ initWidgets }) => initWidgets())
            .catch((e) => console.error('[widgets] panel hydration failed', e));
    }

    if (needsBuilder) {
        import('./widgets/builder.js')
            .then(({ initDashboardBuilder }) => initDashboardBuilder())
            .catch((e) => console.error('[widgets] builder failed to load', e));
    }
});

/*
| Canvas sizing, re-applied per pageview.
|
| The wrapper itself lives in an inline head script in layouts/app.blade.php
| (window.wrapSizedCanvases) and is called from window.onPageReady BEFORE each
| page's chart code runs — a canvas has to be inside its fixed-height box
| before Chart.js is constructed against it, or the chart binds its
| ResizeObserver to a box it is about to leave. See that script for the full
| reasoning. Pages that load this bundle without the layout (the auth screens)
| have no charts, so a no-op stand-in is enough there.
*/
window.wrapSizedCanvases ??= () => {};

/*
| wire:navigate never reloads the document, so a Chart instance whose canvas
| was just swapped out stays in Chart.instances forever — holding its data, its
| canvas and a live ResizeObserver that keeps firing on every window resize.
| Twenty visits around the dashboards used to leave dozens of them behind.
| The canvases of the incoming page are already attached by the time
| livewire:navigated fires, so anything still detached is genuinely dead.
*/
const destroyDetachedCharts = () => {
    Object.values(Chart.instances).forEach((chart) => {
        if (!chart.canvas?.isConnected) chart.destroy();
    });
};

document.addEventListener('livewire:navigated', () => {
    destroyDetachedCharts();
    window.wrapSizedCanvases();
});

/*
| Global live-search helper.
|
| Any input with `data-live-search` auto-submits its parent form ~350ms after
| the user stops typing, so list/table filters react without pressing Enter.
| The same helper re-applies on <select> changes inside a form tagged
| `data-live-filter`.
*/
const timers = new WeakMap();

const submitDebounced = (form, delay) => {
    clearTimeout(timers.get(form));
    timers.set(
        form,
        setTimeout(() => form.submit(), delay)
    );
};

document.addEventListener('input', (event) => {
    const el = event.target;
    if (!(el instanceof HTMLInputElement)) return;
    if (!el.hasAttribute('data-live-search')) return;

    const form = el.form || el.closest('form');
    if (!form) return;

    submitDebounced(form, 350);
});

document.addEventListener('change', (event) => {
    const el = event.target;
    if (!(el instanceof HTMLSelectElement)) return;

    const form = el.form || el.closest('form');
    if (!form || !form.hasAttribute('data-live-filter')) return;

    submitDebounced(form, 0);
});
