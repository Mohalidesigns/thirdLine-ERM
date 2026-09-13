import { useCallback, useRef, useState } from 'react';

const NODE_W = 160;
const NODE_H = 56;
const GRID = 20;

const TYPE_TONES = {
    start: { fill: '#DCFCE7', stroke: '#16A34A', text: '#14532D' },
    end: { fill: '#FEE2E2', stroke: '#DC2626', text: '#7F1D1D' },
    approval: { fill: '#DBEAFE', stroke: '#2563EB', text: '#1E3A8A' },
    task: { fill: '#F1F5F9', stroke: '#64748B', text: '#1E293B' },
    exclusive_gateway: { fill: '#FEF3C7', stroke: '#D97706', text: '#78350F' },
    parallel_gateway: { fill: '#FEF3C7', stroke: '#D97706', text: '#78350F' },
    join: { fill: '#FEF3C7', stroke: '#D97706', text: '#78350F' },
    timer: { fill: '#EDE9FE', stroke: '#7C3AED', text: '#4C1D95' },
    service_task: { fill: '#E0F2FE', stroke: '#0284C7', text: '#0C4A6E' },
    sub_process: { fill: '#E0F2FE', stroke: '#0284C7', text: '#0C4A6E' },
    escalation: { fill: '#FFEDD5', stroke: '#EA580C', text: '#7C2D12' },
    notification: { fill: '#F1F5F9', stroke: '#64748B', text: '#1E293B' },
};

const toneFor = (type) => TYPE_TONES[type] ?? TYPE_TONES.task;

/**
 * Where an edge leaves one box and meets another.
 *
 * Anchored on the facing edges rather than the centres, so a line does not run
 * underneath the box it starts from.
 */
function anchors(from, to) {
    const fromRight = from.x + NODE_W / 2 <= to.x + NODE_W / 2;

    return {
        x1: fromRight ? from.x + NODE_W : from.x,
        y1: from.y + NODE_H / 2,
        x2: fromRight ? to.x : to.x + NODE_W,
        y2: to.y + NODE_H / 2,
    };
}

/**
 * The workflow canvas (migration Phase 6.5).
 *
 * Plain SVG, no diagram library — the same choice every other chart in this
 * product makes. Nodes carry their own x/y, so there is no layout algorithm to
 * disagree with: **a process looks the same to the next person who opens it.**
 * A layout that reshuffles on every load is a diagram nobody trusts and
 * everybody redraws.
 *
 * Dragging snaps to a 20px grid, as the Livewire designer did. A diagram whose
 * boxes are two pixels out of line reads as sloppy, and nobody wants to nudge
 * them by hand.
 */
export default function DesignerCanvas({
    nodes,
    edges,
    selectedNode,
    selectedEdge,
    onSelectNode,
    onSelectEdge,
    onMoveNode,
    readOnly = false,
}) {
    const svgRef = useRef(null);
    const [dragging, setDragging] = useState(null);

    const byCode = new Map(nodes.map((node) => [node.code, node]));

    const width = Math.max(900, ...nodes.map((n) => (n.x ?? 0) + NODE_W + 80));
    const height = Math.max(420, ...nodes.map((n) => (n.y ?? 0) + NODE_H + 80));

    const pointAt = useCallback((event) => {
        const svg = svgRef.current;
        if (!svg) return { x: 0, y: 0 };

        const rect = svg.getBoundingClientRect();
        const scaleX = svg.viewBox.baseVal.width / rect.width;
        const scaleY = svg.viewBox.baseVal.height / rect.height;

        return {
            x: (event.clientX - rect.left) * scaleX,
            y: (event.clientY - rect.top) * scaleY,
        };
    }, []);

    const startDrag = (event, node) => {
        if (readOnly) return;

        event.stopPropagation();
        onSelectNode(node.code);

        const point = pointAt(event);
        setDragging({ code: node.code, dx: point.x - (node.x ?? 0), dy: point.y - (node.y ?? 0) });
        event.currentTarget.setPointerCapture?.(event.pointerId);
    };

    const onDrag = (event) => {
        if (!dragging) return;

        const point = pointAt(event);
        const x = Math.max(0, Math.round((point.x - dragging.dx) / GRID) * GRID);
        const y = Math.max(0, Math.round((point.y - dragging.dy) / GRID) * GRID);

        onMoveNode(dragging.code, x, y);
    };

    const endDrag = () => setDragging(null);

    return (
        <div className="overflow-auto bg-white rounded-xl border border-gray-200">
            <svg
                ref={svgRef}
                viewBox={`0 0 ${width} ${height}`}
                width={width}
                height={height}
                className="block select-none"
                onPointerMove={onDrag}
                onPointerUp={endDrag}
                onPointerLeave={endDrag}
                onClick={() => {
                    onSelectNode(null);
                    onSelectEdge(null);
                }}
                role="img"
                aria-label="Workflow diagram"
            >
                <defs>
                    <pattern id="wf-grid" width={GRID} height={GRID} patternUnits="userSpaceOnUse">
                        <path d={`M ${GRID} 0 L 0 0 0 ${GRID}`} fill="none" stroke="#F1F5F9" strokeWidth="1" />
                    </pattern>
                    <marker
                        id="wf-arrow"
                        viewBox="0 0 10 10"
                        refX="9"
                        refY="5"
                        markerWidth="6"
                        markerHeight="6"
                        orient="auto-start-reverse"
                    >
                        <path d="M 0 0 L 10 5 L 0 10 z" fill="#94A3B8" />
                    </marker>
                    <marker
                        id="wf-arrow-on"
                        viewBox="0 0 10 10"
                        refX="9"
                        refY="5"
                        markerWidth="6"
                        markerHeight="6"
                        orient="auto-start-reverse"
                    >
                        <path d="M 0 0 L 10 5 L 0 10 z" fill="#1A365D" />
                    </marker>
                </defs>

                <rect width={width} height={height} fill="url(#wf-grid)" />

                {edges.map((edge, index) => {
                    const from = byCode.get(edge.from);
                    const to = byCode.get(edge.to);

                    if (!from || !to) return null;

                    const { x1, y1, x2, y2 } = anchors(from, to);
                    const on = selectedEdge === index;
                    const midX = (x1 + x2) / 2;
                    const midY = (y1 + y2) / 2;

                    return (
                        <g
                            key={`${edge.from}-${edge.to}-${index}`}
                            onClick={(event) => {
                                event.stopPropagation();
                                onSelectEdge(index);
                            }}
                            className="cursor-pointer"
                        >
                            {/* A wide invisible line so the edge is clickable
                                without demanding pixel accuracy. */}
                            <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="transparent" strokeWidth="14" />
                            <line
                                x1={x1}
                                y1={y1}
                                x2={x2}
                                y2={y2}
                                stroke={on ? '#1A365D' : '#94A3B8'}
                                strokeWidth={on ? 2.5 : 1.5}
                                markerEnd={`url(#${on ? 'wf-arrow-on' : 'wf-arrow'})`}
                            />
                            {(edge.label || edge.when) && (
                                <>
                                    <rect
                                        x={midX - 52}
                                        y={midY - 11}
                                        width="104"
                                        height="22"
                                        rx="11"
                                        fill="#FFFFFF"
                                        stroke={on ? '#1A365D' : '#E2E8F0'}
                                    />
                                    <text
                                        x={midX}
                                        y={midY + 4}
                                        textAnchor="middle"
                                        fontSize="10"
                                        fill={on ? '#1A365D' : '#64748B'}
                                    >
                                        {(edge.label || edge.when).slice(0, 18)}
                                    </text>
                                </>
                            )}
                        </g>
                    );
                })}

                {nodes.map((node) => {
                    const tone = toneFor(node.type);
                    const on = selectedNode === node.code;

                    return (
                        <g
                            key={node.code}
                            transform={`translate(${node.x ?? 0}, ${node.y ?? 0})`}
                            onPointerDown={(event) => startDrag(event, node)}
                            onClick={(event) => {
                                event.stopPropagation();
                                onSelectNode(node.code);
                            }}
                            className={readOnly ? 'cursor-pointer' : 'cursor-move'}
                        >
                            <rect
                                width={NODE_W}
                                height={NODE_H}
                                rx="10"
                                fill={tone.fill}
                                stroke={on ? '#1A365D' : tone.stroke}
                                strokeWidth={on ? 2.5 : 1.5}
                            />
                            <text x="12" y="23" fontSize="12" fontWeight="600" fill={tone.text}>
                                {(node.name ?? node.code).slice(0, 22)}
                            </text>
                            <text x="12" y="40" fontSize="10" fill={tone.text} opacity="0.75">
                                {node.type}
                                {node.sla_hours ? ` · ${node.sla_hours}h` : ''}
                            </text>
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}
