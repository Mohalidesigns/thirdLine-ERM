import { useEffect, useRef, useState } from 'react';
import { alpha, readTokens } from '../theme';

/**
 * Radial neighbourhood graph in plain SVG: anchor centred, first hop on an
 * inner ring, everything else outer. Peripheral vision, not an explorer.
 */
export default function Network({ data }) {
    const hostRef = useRef(null);
    const [size, setSize] = useState({ W: 480, H: 260 });
    const [tokens, setTokens] = useState(() => readTokens());

    useEffect(() => {
        const el = hostRef.current;
        if (el) setSize({ W: el.clientWidth || 480, H: Math.max(el.clientHeight || 0, 260) });
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onScheme = () => setTokens(readTokens());
        media.addEventListener('change', onScheme);
        return () => media.removeEventListener('change', onScheme);
    }, []);

    const nodes = data.nodes || [];
    const edges = data.edges || [];

    if (nodes.length <= 1) {
        return <div ref={hostRef} className="h-full"><p className="widget-empty-note">No data in scope</p></div>;
    }

    const { W, H } = size;
    const cx = W / 2;
    const cy = H / 2;
    const anchor = nodes.find((n) => n.is_anchor) || nodes[0];
    const firstHop = new Set();
    edges.forEach((e) => {
        if (e.from === anchor.id) firstHop.add(e.to);
        if (e.to === anchor.id) firstHop.add(e.from);
    });
    const inner = nodes.filter((n) => firstHop.has(n.id));
    const outer = nodes.filter((n) => n.id !== anchor.id && !firstHop.has(n.id));

    const pos = new Map([[anchor.id, { x: cx, y: cy }]]);
    const place = (list, radius) => list.forEach((n, i) => {
        const angle = (2 * Math.PI * i) / Math.max(1, list.length) - Math.PI / 2;
        pos.set(n.id, { x: cx + radius * Math.cos(angle), y: cy + radius * Math.sin(angle) });
    });
    place(inner, Math.min(W, H) * 0.28);
    place(outer, Math.min(W, H) * 0.44);

    return (
        <div ref={hostRef} className="h-full">
            <svg viewBox={`0 0 ${W} ${H}`} className="widget-network" role="img" aria-label={`Relationship network around ${anchor.label}`}>
                {edges.map((e, i) => {
                    const a = pos.get(e.from);
                    const b = pos.get(e.to);
                    if (!a || !b) return null;
                    return (
                        <line key={`e-${i}`} x1={a.x} y1={a.y} x2={b.x} y2={b.y} stroke={tokens.grid} strokeWidth="1">
                            <title>{e.code}</title>
                        </line>
                    );
                })}
                {nodes.map((n) => {
                    const p = pos.get(n.id);
                    if (!p) return null;
                    return (
                        <g key={n.id}>
                            <circle cx={p.x} cy={p.y} r={n.is_anchor ? 14 : 8} fill={n.is_anchor ? tokens.series[0] : alpha(tokens.series[0], 0.35)} stroke={tokens.series[0]} />
                            <title>{`${n.label}${n.type ? ` (${n.type})` : ''}`}</title>
                            <text x={p.x} y={p.y + (n.is_anchor ? 28 : 20)} textAnchor="middle" className="widget-network-label" fill={tokens.inkSecondary}>
                                {n.label.length > 18 ? `${n.label.slice(0, 17)}…` : n.label}
                            </text>
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}
