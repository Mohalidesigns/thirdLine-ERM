import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The CSV exports, in one menu (migration Phase 5.5).
 *
 * Sixteen routes on `Risk/ExportController`, which this phase leaves otherwise
 * untouched. They were reachable only from whichever screen happened to link
 * one, so most were reachable from nowhere at all.
 *
 * EVERY ITEM IS GATED ON `report.export`, which is the permission the routes
 * themselves carry. The check here is a courtesy — it hides a link the server
 * would refuse — not the enforcement; that stays on the route.
 *
 * The links are plain anchors: the response is a file, and an Inertia visit
 * expects a page. A route the Ziggy manifest does not carry (a module behind a
 * feature flag) resolves to null and its item is dropped.
 */
const GROUPS = [
    {
        label: 'Register',
        items: [
            ['risk.export.register', 'Risk register'],
            ['risk.export.assessments', 'Risk assessments'],
            ['risk.export.controls', 'Controls'],
            ['risk.export.appetite', 'Risk appetite'],
            ['risk.export.rcsa-matrix', 'RCSA matrix'],
            ['risk.export.dashboard', 'Dashboard summary'],
        ],
    },
    {
        label: 'Loss events',
        items: [
            ['risk.export.loss-events', 'All loss events'],
            ['risk.export.loss-events.full', 'Loss events — full detail'],
            ['risk.export.loss-events.cbn-orms', 'CBN ORMS return'],
            ['risk.export.loss-events.basel', 'Basel categorisation'],
            ['risk.export.loss-events.nfiu', 'NFIU return'],
            ['risk.export.loss-events.management', 'Management summary'],
            ['risk.export.loss-events.trends', 'Loss trends'],
        ],
    },
    {
        label: 'Issues and quantification',
        items: [
            ['risk.export.issues', 'Issues'],
            ['risk.export.issues-ageing', 'Issues ageing'],
            ['risk.export.quantification-results', 'Simulation results'],
        ],
    },
];

export default function ExportMenu({ label = 'Export', className = '' }) {
    const [open, setOpen] = useState(false);

    const permissions = usePage().props.auth?.permissions ?? [];

    if (!permissions.includes('report.export')) return null;

    const groups = GROUPS.map((group) => ({
        ...group,
        items: group.items
            .map(([name, itemLabel]) => [tryRoute(name), itemLabel])
            .filter(([url]) => url !== null),
    })).filter((group) => group.items.length > 0);

    if (groups.length === 0) return null;

    return (
        <div className={`relative ${className}`}>
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                aria-expanded={open}
                aria-haspopup="menu"
            >
                <span className="material-symbols-outlined text-lg">download</span> {label}
                <span className="material-symbols-outlined text-lg">{open ? 'expand_less' : 'expand_more'}</span>
            </button>

            {open && (
                <>
                    {/* Click-away, so the menu closes without a document listener. */}
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} aria-hidden="true" />

                    <div
                        role="menu"
                        className="absolute right-0 mt-2 w-72 bg-white rounded-xl border border-gray-200 shadow-lg z-20 py-2 max-h-96 overflow-y-auto"
                    >
                        {groups.map((group) => (
                            <div key={group.label}>
                                <p className="px-4 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                    {group.label}
                                </p>
                                {group.items.map(([url, itemLabel]) => (
                                    <a
                                        key={url}
                                        href={url}
                                        role="menuitem"
                                        onClick={() => setOpen(false)}
                                        className="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50"
                                    >
                                        {itemLabel}
                                    </a>
                                ))}
                            </div>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
