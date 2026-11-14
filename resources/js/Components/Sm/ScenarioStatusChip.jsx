import Icon from '@thirdline/ui/Components/Icon';

/**
 * The lifecycle chip (spec 01 §2 — the canonical status-colour table).
 *
 * BESPOKE, AND JUSTIFIED ONCE: `StatusBadge`'s `statusMap` cannot express the
 * signed-off treatment (navy fill plus a `lock` glyph) and adding eight
 * module-specific keys to a component five modules share is how a shared
 * component acquires one module's vocabulary (spec 01 §0.1.1).
 *
 * The index grid cannot use this component — `Column::badge()` renders classes
 * and words only, no React component (spec 00 §3.3) — so `ScenariosGrid`'s
 * STATUS_CLASSES carries the same pairings, and the navy chip is the CSS class
 * `.badge-status-frozen` in `resources/css/app.css` on both surfaces. A class
 * named only inside a PHP array would be purged, because the `@source` globs do
 * not scan `app/`.
 *
 * COLOUR IS NEVER THE ONLY SIGNAL: every chip carries words, and two carry an
 * icon as a third channel. The accessible name is the words alone — "Signed off",
 * not "lock Signed off" — so the glyphs are `aria-hidden` (spec 01 §7).
 */
const TREATMENTS = {
    draft: { className: 'badge badge-status-draft', icon: null },
    in_design: { className: 'badge badge-status-pending', icon: null },
    designed: { className: 'badge badge-status-active', icon: null },
    in_validation: { className: 'badge badge-medium', icon: null },
    validated: { className: 'badge badge-status-completed', icon: null },
    // Navy on white, ≈12:1: signed-off is the one state an examiner scans for,
    // so it is the only chip that inverts, and it uses the theme's own token.
    signed_off: { className: 'badge badge-status-frozen', icon: 'lock' },
    returned_for_rework: { className: 'badge badge-status-overdue', icon: 'priority_high' },
    archived: { className: 'badge badge-status-draft', icon: 'inventory_2' },
};

/** An unmapped status is not styled as an error — it falls back to neutral grey. */
const FALLBACK = { className: 'badge badge-status-draft', icon: null };

export default function ScenarioStatusChip({ status, label, className = '' }) {
    const treatment = TREATMENTS[status] || FALLBACK;
    // The words are the server's (`status_label`), so a tenant that renames a
    // stage renames it here too with no front-end release.
    const words = label || status;

    return (
        <span className={`${treatment.className} gap-1 ${className}`}>
            {treatment.icon && <Icon name={treatment.icon} className="h-3.5 w-3.5" />}
            {words}
        </span>
    );
}
