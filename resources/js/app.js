import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import Chart from 'chart.js/auto';
import { initWidgets } from './widgets';
import { initDashboardBuilder } from './widgets/builder';

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
| WP-08 widget engine hydration: finds [data-widget] panels, renders their
| chart payloads, and keeps them alive across Livewire morphs, wire:navigate
| visits and colour scheme changes. Both init functions register document-
| level listeners (including their own livewire:navigated handlers), so they
| run EXACTLY ONCE per browser session — never per navigation.
*/
window.onPageReady(() => {
    initWidgets();
    initDashboardBuilder();
});

/*
| Canvas sizing fix, re-applied per pageview: wrap any canvas that declares a
| height attribute in a fixed-height relative container, otherwise Chart.js's
| responsive resize loop grows the canvas indefinitely. Idempotent — wrapped
| canvases are skipped via the parent's data-chart-wrap marker.
*/
const wrapSizedCanvases = () => {
    document.querySelectorAll('canvas').forEach((canvas) => {
        const height = canvas.getAttribute('height');
        if (!height) return;

        const parent = canvas.parentElement;
        if (!parent || parent.dataset.chartWrap) return;

        const wrapper = document.createElement('div');
        wrapper.dataset.chartWrap = '1';
        wrapper.style.position = 'relative';
        wrapper.style.height = `${height}px`;
        wrapper.style.width = '100%';

        parent.insertBefore(wrapper, canvas);
        wrapper.appendChild(canvas);
    });
};

/*
| Inline page scripts run before livewire:navigated fires, so charts created
| by window.onPageReady callbacks exist by the time their canvases are
| wrapped — the same ordering as DOMContentLoaded on a full page load.
*/
window.onPageReady(wrapSizedCanvases);
document.addEventListener('livewire:navigated', wrapSizedCanvases);

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
