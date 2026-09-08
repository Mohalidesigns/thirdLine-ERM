/**
 * The tree, drawn once and used by three screens.
 *
 * INLINE SVG-FREE ON PURPOSE. A canvas with drag-to-reposition looks better in
 * a screenshot and is worse to use: a call tree is read on a phone at three in
 * the morning by somebody who needs to know who is below them, and nested rows
 * with indent guides survive a 360px screen where a canvas does not. Re-parent
 * is a control on the row rather than a drag, for the same reason.
 *
 * THE FOUR STATES ARE FOUR COLOURS AND ALSO FOUR WORDS. Colour alone fails for
 * the ~8% of men with a red/green deficiency, and this is a screen somebody
 * reads under pressure.
 */

const STATES = {
    reached: { dot: 'bg-emerald-500', ring: 'ring-emerald-200', label: 'Reached' },
    pending: { dot: 'bg-amber-500', ring: 'ring-amber-200', label: 'Waiting' },
    waiting: { dot: 'bg-slate-300', ring: 'ring-slate-200', label: 'Not yet called' },
    failed: { dot: 'bg-rose-600', ring: 'ring-rose-200', label: 'Failed' },
    blocked: { dot: 'bg-slate-400', ring: 'ring-slate-200', label: 'Never called' },
    excluded: { dot: 'bg-violet-500', ring: 'ring-violet-200', label: 'Excluded' },
};

function Row({ node, depth, onSelect, selectedId, showState, brokenIds }) {
    const state = STATES[node.state] ?? STATES.waiting;
    const isBroken = brokenIds?.has(node.test_node_id ?? node.id);
    const id = node.test_node_id ?? node.id;

    return (
        <li>
            <button
                type="button"
                onClick={() => onSelect?.(node)}
                style={{ paddingLeft: `${depth * 20 + 8}px` }}
                className={[
                    'flex w-full items-center gap-3 rounded border-l-2 py-2 pr-3 text-left text-sm transition',
                    selectedId === id ? 'bg-slate-100 border-slate-400' : 'border-transparent hover:bg-slate-50',
                    isBroken ? 'ring-1 ring-rose-300' : '',
                ].join(' ')}
            >
                {showState && (
                    <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${state.dot}`} aria-hidden="true" />
                )}

                <span className="shrink-0 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                    T{node.tier}
                </span>

                <span className="min-w-0 flex-1 truncate">
                    <span className="font-medium text-slate-800">{node.name ?? 'Unassigned'}</span>
                    {node.role_label && <span className="text-slate-500"> · {node.role_label}</span>}
                </span>

                {node.is_must_reach && (
                    <span className="shrink-0 rounded bg-slate-800 px-1.5 py-0.5 text-[10px] font-medium text-white">
                        must reach
                    </span>
                )}

                {node.downstream_blocked_count > 0 && (
                    <span className="shrink-0 rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                        {node.downstream_blocked_count} isolated
                    </span>
                )}

                {!showState && node.downstream_count > 0 && (
                    <span className="shrink-0 text-[11px] text-slate-500">{node.downstream_count} below</span>
                )}

                {showState && (
                    <span className="shrink-0 text-[11px] text-slate-500">
                        {state.label}
                        {node.response_minutes != null && ` · ${node.response_minutes}m`}
                    </span>
                )}
            </button>

            {node.children?.length > 0 && (
                <ul>
                    {node.children.map((child) => (
                        <Row
                            key={child.test_node_id ?? child.id}
                            node={child}
                            depth={depth + 1}
                            onSelect={onSelect}
                            selectedId={selectedId}
                            showState={showState}
                            brokenIds={brokenIds}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
}

export default function CascadeTree({
    nodes = [], onSelect, selectedId = null, showState = false, brokenIds = null, empty = 'No nodes yet.',
}) {
    if (!nodes.length) {
        return <p className="px-3 py-6 text-sm text-slate-500">{empty}</p>;
    }

    return (
        <ul className="divide-y divide-slate-100">
            {nodes.map((node) => (
                <Row
                    key={node.test_node_id ?? node.id}
                    node={node}
                    depth={0}
                    onSelect={onSelect}
                    selectedId={selectedId}
                    showState={showState}
                    brokenIds={brokenIds}
                />
            ))}
        </ul>
    );
}

export { STATES };
