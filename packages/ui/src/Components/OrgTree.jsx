import { Link } from '@inertiajs/react';
import { useState } from 'react';

function Node({ node, currentId, hrefFor }) {
    const [open, setOpen] = useState(node.depth < 2 || node.id === currentId);
    const hasChildren = Array.isArray(node.children) && node.children.length > 0;
    const active = node.id === currentId;

    return (
        <li>
            <div className="group flex items-center gap-1">
                {hasChildren ? (
                    <button type="button" onClick={() => setOpen((o) => !o)} className="shrink-0 text-gray-300 hover:text-gray-500" aria-label={open ? 'Collapse' : 'Expand'}>
                        <span className="material-symbols-outlined text-[16px] leading-none">{open ? 'expand_more' : 'chevron_right'}</span>
                    </button>
                ) : (
                    <span className="w-4 shrink-0" />
                )}
                <Link
                    href={hrefFor(node)}
                    className={`flex min-w-0 flex-1 items-center gap-1.5 rounded px-1.5 py-1 text-xs ${active ? 'bg-[var(--color-primary)] font-semibold text-white' : 'text-gray-700 hover:bg-gray-100'}`}
                    title={node.type}
                >
                    {node.icon && <span className="material-symbols-outlined shrink-0 text-[15px] leading-none opacity-70">{node.icon}</span>}
                    <span className="truncate">{node.name}</span>
                </Link>
            </div>
            {hasChildren && open && <OrgTree nodes={node.children} currentId={currentId} hrefFor={hrefFor} />}
        </li>
    );
}

/** Recursive org-tree navigator (migration Phase 2: hq/partials/tree.blade.php). */
export default function OrgTree({ nodes, currentId, hrefFor }) {
    if (!Array.isArray(nodes) || nodes.length === 0) return null;
    const nested = (nodes[0]?.depth ?? 0) > 0;

    return (
        <ul className={`space-y-0.5 ${nested ? 'ml-3 border-l border-gray-100 pl-2' : ''}`}>
            {nodes.map((node) => (
                <Node key={node.id} node={node} currentId={currentId} hrefFor={hrefFor} />
            ))}
        </ul>
    );
}
