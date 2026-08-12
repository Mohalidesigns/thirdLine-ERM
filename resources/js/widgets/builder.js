/*
| GridStack glue for the dashboard builder.
|
| Livewire owns the layout truth; GridStack is only the drag/resize surface.
| The grid initialises over [data-builder-grid], reports every change back
| through $wire.updateLayout([{position, x, y, w, h}]), and is torn down and
| rebuilt whenever the component re-renders the item set (tab switch, add,
| remove) — GridStack cannot morph and must not fight Livewire over the DOM.
*/

import { GridStack } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';

let grid = null;

function findWire(el) {
    const host = el.closest('[wire\\:id]');
    return host && window.Livewire ? window.Livewire.find(host.getAttribute('wire:id')) : null;
}

function serialize(gridInstance) {
    return gridInstance.engine.nodes.map((node) => ({
        position: parseInt(node.el?.dataset.position ?? '-1', 10),
        x: node.x, y: node.y, w: node.w, h: node.h,
    }));
}

function initBuilderGrid() {
    const el = document.querySelector('[data-builder-grid]');

    if (grid) {
        grid.destroy(false);
        grid = null;
    }

    if (!el) return;

    grid = GridStack.init(
        {
            column: 12,
            cellHeight: 92,
            margin: 8,
            float: false,
            animate: true,
        },
        el
    );

    grid.on('change', () => {
        const wire = findWire(el);
        if (wire) wire.call('updateLayout', serialize(grid));
    });
}

export function initDashboardBuilder() {
    if (!document.querySelector('[data-dashboard-builder]')) return;

    initBuilderGrid();

    // Rebuild after any Livewire update that touched the grid subtree.
    document.addEventListener('builder-grid-reload', () => {
        // Wait for the morph to settle before re-scanning the DOM.
        queueMicrotask(() => requestAnimationFrame(() => initBuilderGrid()));
    });

    if (window.Livewire?.hook) {
        window.Livewire.hook('morphed', () => {
            const el = document.querySelector('[data-builder-grid]');
            if (el && !el.classList.contains('grid-stack-instance')) {
                // A tab switch replaced the container wholesale.
                if (!el.gridstack) initBuilderGrid();
            }
        });
    }
}
