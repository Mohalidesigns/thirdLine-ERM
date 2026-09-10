import { Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Level → colour, as risk/scoping/_tree-node.blade.php had them. A level
 * beyond the map falls back to the neutral chip.
 */
const LEVELS = {
    0: { chip: 'bg-[#1A365D] text-white', icon: 'text-[#1A365D]', fallbackIcon: 'domain' },
    1: { chip: 'bg-[#2D7D46] text-white', icon: 'text-[#2D7D46]', fallbackIcon: 'account_balance' },
    2: { chip: 'bg-[#D4AF37] text-[#1A365D]', icon: 'text-[#D4AF37]', fallbackIcon: 'store' },
    3: { chip: 'bg-green-500 text-white', icon: 'text-green-500', fallbackIcon: 'location_on' },
    4: { chip: 'bg-gray-200 text-gray-700', icon: 'text-gray-500', fallbackIcon: 'location_city' },
};

function levelStyle(level) {
    return LEVELS[level] ?? LEVELS[4];
}

function Node({ node, currentId, hrefFor, expandDepth }) {
    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    const [open, setOpen] = useState(node.depth < expandDepth || node.id === currentId);
    const active = node.id === currentId;
    const style = levelStyle(node.level);

    return (
        <li>
            <div className="group flex items-center gap-1.5">
                {hasChildren ? (
                    <button
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        className="shrink-0 text-gray-300 hover:text-gray-500"
                        aria-label={open ? 'Collapse' : 'Expand'}
                        aria-expanded={open}
                    >
                        <span className="material-symbols-outlined text-[16px] leading-none">{open ? 'expand_more' : 'chevron_right'}</span>
                    </button>
                ) : (
                    <span className="w-4 shrink-0 text-center text-gray-300 text-sm leading-none">•</span>
                )}
                <Link
                    href={hrefFor(node)}
                    className={`flex min-w-0 flex-1 items-center gap-2 rounded px-2 py-1.5 transition-colors ${active ? 'bg-blue-50 ring-1 ring-blue-200' : 'hover:bg-blue-50/50'}`}
                    title={node.code}
                >
                    <span className={`material-symbols-outlined shrink-0 text-[16px] leading-none ${style.icon}`}>{node.icon || style.fallbackIcon}</span>
                    <span className={`truncate text-sm font-semibold ${node.depth === 0 ? 'text-[#1A365D]' : 'text-gray-800'} group-hover:text-[#1A365D]`}>{node.name}</span>
                    <span className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] ${style.chip}`}>
                        L{node.level} {node.type ?? ''}
                    </span>
                    {node.status && node.status !== 'active' && (
                        <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-500">{node.status}</span>
                    )}
                </Link>
            </div>
            {hasChildren && open && (
                <EntityTree nodes={node.children} currentId={currentId} hrefFor={hrefFor} expandDepth={expandDepth} />
            )}
        </li>
    );
}

/**
 * The entity hierarchy navigator (migration Phase 3.1: the recursive
 * risk/scoping/_tree-node.blade.php partial). Nodes come nested from
 * App\Services\Scoping\EntityService::tree(); each links to its detail page.
 */
export default function EntityTree({ nodes, currentId = null, hrefFor, expandDepth = 2 }) {
    if (!Array.isArray(nodes) || nodes.length === 0) return null;

    const nested = (nodes[0]?.depth ?? 0) > 0;
    const linkFor = hrefFor ?? ((node) => route('risk.scoping.show', node.id));

    return (
        <ul className={`space-y-0.5 ${nested ? 'ml-4 border-l border-gray-100 pl-2' : ''}`}>
            {nodes.map((node) => (
                <Node key={node.id} node={node} currentId={currentId} hrefFor={linkFor} expandDepth={expandDepth} />
            ))}
        </ul>
    );
}
