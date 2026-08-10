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
| Chart.js sizing fix: wrap any canvas that declares a height attribute in a
| fixed-height relative container, otherwise Chart.js's responsive resize loop
| grows the canvas indefinitely.
*/
document.addEventListener('DOMContentLoaded', () => {
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
