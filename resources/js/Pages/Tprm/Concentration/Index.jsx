import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Concentration and the supply chain — FR-NTH-02 through FR-NTH-06.
 *
 * THE TABLE IS THE SCREEN AND THE GRAPH IS BESIDE IT. A force-directed diagram
 * of forty vendors is the thing every demo asks for and the thing no decision
 * is made from; the ranked single-points-of-failure list is what goes in a
 * board pack. Both are always rendered, which is also why this screen needs no
 * separate "accessible version": the numbers live in a real table with real
 * headers, and the SVG is a labelled picture of them.
 *
 * NOTHING HERE RECOMPUTES ON RENDER. The figures are a stored snapshot with a
 * date on it, because two people opening the same screen five minutes apart
 * seeing different HHIs — and neither able to say why — is worse than figures
 * that are a week old and say so.
 */
export default function Index({
    dimension, dimensions = [], analysis = null, bandEdges = {}, thresholds = {},
    graph = null, proposals = [], can = {},
}) {
    const [tab, setTab] = useState('spof');

    const breaches = analysis?.breaches ?? [];
    const clusters = analysis?.clusters ?? [];
    const spof = analysis?.spof ?? [];

    const tabs = [
        ['spof', `Single points of failure (${spof.length})`],
        ['clusters', `Clusters (${clusters.length})`],
        ['graph', 'Supply chain'],
        ['proposals', `Proposed sub-processors (${proposals.length})`],
    ];

    return (
        <AppLayout title="Concentration">
            <Head title="Concentration" />

            <PageHeader
                title="Concentration"
                subtitle="Where the portfolio's dependencies overlap, and what stops if one of them stops. Figures are a stored snapshot, not a live calculation."
                actions={can.manage ? (
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => router.post(route('tprm.concentration.run'), { dimension })}
                    >
                        Run analysis
                    </button>
                ) : null}
            />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <label htmlFor="dimension" className="text-sm text-gray-600">Grouped by</label>
                <select
                    id="dimension"
                    className="form-select text-sm"
                    value={dimension}
                    onChange={(event) => router.get(
                        route('tprm.concentration.index'),
                        { dimension: event.target.value },
                        { preserveScroll: true },
                    )}
                >
                    {dimensions.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </select>
            </div>

            {analysis === null ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    This dimension has not been analysed yet. Concentration is not computed on page load — it is a
                    portfolio-wide walk, and a figure with no date on it cannot be compared to last quarter's.
                    {can.manage && ' Run it above.'}
                </div>
            ) : (
                <>
                    <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <Tile
                            label="Concentration index"
                            value={Number(analysis.hhi).toFixed(0)}
                            tone={analysis.band === 'concentrated' ? 'critical' : analysis.band === 'moderate' ? 'warn' : null}
                            hint={analysis.band_label}
                        />
                        <Tile
                            label="Movement"
                            value={analysis.movement === null ? '—' : `${analysis.movement > 0 ? '+' : ''}${analysis.movement}`}
                            tone={analysis.movement > 0 ? 'warn' : null}
                            hint={analysis.movement === null ? 'no previous run to compare' : 'since the previous run'}
                        />
                        <Tile
                            label="Thresholds breached"
                            value={breaches.length}
                            tone={breaches.length ? 'critical' : null}
                            hint={`limit ${thresholds.max_critical_functions_per_group} critical functions per group`}
                        />
                        <Tile
                            label="Analysed"
                            value={clusters.length}
                            hint={`groups · ${analysis.run_at}`}
                        />
                    </div>

                    {breaches.length > 0 && (
                        <div className="card mb-6 border-l-4 border-red-500 p-4">
                            <h2 className="text-sm font-semibold text-red-800">Threshold breaches</h2>
                            <p className="mt-0.5 text-xs text-gray-600">
                                These do not block anything. Concentration is a judgement about the institution's
                                shape, not a control somebody violated — a hard block would be routed around within
                                a week.
                            </p>
                            <ul className="mt-3 space-y-2">
                                {breaches.map((breach, index) => (
                                    <li key={`${breach.threshold}-${breach.cluster ?? index}`} className="text-sm text-gray-800">
                                        {breach.message}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
                        {tabs.map(([key, label]) => (
                            <button key={key} type="button" onClick={() => setTab(key)}
                                className={`px-3 py-2 text-sm font-medium ${
                                    tab === key ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-500 hover:text-gray-700'
                                }`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {tab === 'spof' && <SpofTable rows={spof} />}
                    {tab === 'clusters' && <ClusterTable rows={clusters} bandEdges={bandEdges} />}
                    {tab === 'graph' && <SupplyChain graph={graph} />}
                    {tab === 'proposals' && <Proposals rows={proposals} can={can} />}
                </>
            )}
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${
                tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
            }`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

/**
 * FR-NTH-04. Ranked, with substitutability and time to replace.
 *
 * "Time to replace" is shown as unknown rather than estimated where nobody has
 * recorded one. A number the tool invented would be the number that ends up in
 * a resolution plan.
 */
function SpofTable({ rows }) {
    if (rows.length === 0) {
        return (
            <div className="card p-8 text-center text-sm text-gray-500">
                No single points of failure at this grouping. That is either good news or a sign the register does
                not yet record which business functions each engagement supports.
            </div>
        );
    }

    return (
        <div className="card overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <caption className="sr-only">
                    Providers ranked by the number of critical business functions depending on them
                </caption>
                <thead className="bg-gray-50">
                    <tr>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Provider</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Critical functions</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Engagements</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Substitutability</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Time to replace</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Exit plan</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Note</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.map((row) => (
                        <tr key={row.label}>
                            <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">{row.label}</th>
                            <td className="px-4 py-2 text-right tabular-nums">{row.critical_functions}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{row.engagements}</td>
                            <td className="px-4 py-2">{row.substitutability ?? <Unknown />}</td>
                            <td className="px-4 py-2 text-right tabular-nums">
                                {row.time_to_replace_months ? `${row.time_to_replace_months} months` : <Unknown />}
                            </td>
                            <td className="px-4 py-2"><ExitPlanCell plan={row.exit_plan} /></td>
                            <td className="px-4 py-2 text-gray-600">{row.note}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Unknown() {
    return <span className="text-gray-400">not recorded</span>;
}

/**
 * Exit-plan coverage across the cluster, counting TESTED separately.
 *
 * "3 of 4 have a plan" and "1 of those has ever been tested" are different
 * facts, and rolling them into one figure is how a portfolio arrives at a
 * board meeting believing it can leave a provider it has never tried leaving.
 */
function ExitPlanCell({ plan }) {
    if (!plan || !plan.engagements) return <Unknown />;

    return (
        <span className="text-xs">
            {plan.engagements_with_plan}/{plan.engagements} planned
            <span className={plan.tested ? 'text-gray-600' : 'text-amber-700'}>
                {' · '}{plan.tested} tested
            </span>
        </span>
    );
}

function ClusterTable({ rows, bandEdges }) {
    if (rows.length === 0) {
        return <div className="card p-8 text-center text-sm text-gray-500">Nothing to group at this dimension.</div>;
    }

    const total = rows.reduce((sum, row) => sum + (row.spend_minor ?? 0), 0);

    return (
        <div className="card overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <caption className="px-4 py-2 text-left text-xs text-gray-500">
                    The index is the sum of squared shares, on a 0–10,000 scale. Below {bandEdges.diversified} is
                    diversified; above {bandEdges.concentrated} is concentrated.
                </caption>
                <thead className="bg-gray-50">
                    <tr>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Group</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Engagements</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Critical functions</th>
                        <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Share of spend</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {rows.map((row) => (
                        <tr key={row.label}>
                            <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">{row.label}</th>
                            <td className="px-4 py-2 text-right tabular-nums">{row.engagements}</td>
                            <td className="px-4 py-2 text-right tabular-nums">{row.critical_functions}</td>
                            <td className="px-4 py-2 text-right tabular-nums">
                                {total > 0 ? `${((row.spend_minor / total) * 100).toFixed(1)}%` : <Unknown />}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * FR-NTH-02, and the accessibility decision worth stating.
 *
 * The SVG carries `role="img"` and one label. It is a PICTURE OF the table
 * below it, not a separate source of information — every node in it appears as
 * a row, so a keyboard or screen-reader user loses nothing by the SVG being
 * opaque to them. The alternative, an interactive graph with focusable nodes
 * and an ARIA tree, is a large amount of machinery for a view that is already
 * fully available another way.
 */
function SupplyChain({ graph }) {
    const nodes = graph?.nodes ?? [];
    const edges = graph?.edges ?? [];

    const positioned = useMemo(() => layout(nodes), [nodes]);
    const byId = useMemo(
        () => Object.fromEntries(positioned.map((node) => [node.id, node])),
        [positioned],
    );

    if (nodes.length === 0) {
        return (
            <div className="card p-8 text-center text-sm text-gray-500">
                No sub-processor relationships have been recorded yet. They come from SOC 2 carve-outs, DPA annexes
                and whatever a vendor declares — none of which the module invents.
            </div>
        );
    }

    const height = Math.max(...positioned.map((node) => node.y)) + 60;

    return (
        <div className="space-y-4">
            {graph?.truncated && (
                <div className="card border-l-4 border-amber-400 p-3 text-sm text-gray-700">
                    The chain continues past depth {graph.depth}. What is drawn here is not the whole supply chain —
                    ask for a greater depth to see further.
                </div>
            )}

            <div className="card overflow-x-auto p-4">
                <svg
                    role="img"
                    aria-label={`Supply chain to depth ${graph.depth}: ${nodes.length} entities and ${edges.length} dependencies. The same data is in the table below.`}
                    viewBox={`0 0 900 ${height}`}
                    className="min-w-[700px]"
                >
                    {edges.map((edge) => {
                        const from = byId[edge.from];
                        const to = byId[edge.to];

                        if (!from || !to) return null;

                        return (
                            <line
                                key={`${edge.from}-${edge.to}`}
                                x1={from.x} y1={from.y} x2={to.x} y2={to.y}
                                stroke={edge.criticality === 'critical' ? '#dc2626' : '#cbd5e1'}
                                strokeWidth={edge.criticality === 'critical' ? 2 : 1}
                            />
                        );
                    })}
                    {positioned.map((node) => (
                        <g key={node.id}>
                            <circle cx={node.x} cy={node.y} r="6" fill={riskFill(node.band)} />
                            <text x={node.x + 10} y={node.y + 4} className="text-[10px]" fill="#334155">
                                {node.name}
                            </text>
                        </g>
                    ))}
                </svg>
            </div>

            <div className="card overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <caption className="px-4 py-2 text-left text-xs text-gray-500">
                        Every entity in the diagram above, with its distance from us.
                    </caption>
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Entity</th>
                            <th scope="col" className="px-4 py-2 text-right font-medium text-gray-600">Depth</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Risk band</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Reached through</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {positioned.map((node) => (
                            <tr key={node.id}>
                                <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">
                                    {node.name}
                                    {!node.matched && (
                                        <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs font-normal text-amber-800">
                                            named only
                                        </span>
                                    )}
                                </th>
                                <td className="px-4 py-2 text-right tabular-nums">{node.depth}</td>
                                <td className="px-4 py-2">{node.band ?? <Unknown />}</td>
                                <td className="px-4 py-2 text-gray-600">
                                    {edges.filter((edge) => edge.to === node.id)
                                        .map((edge) => byId[edge.from]?.name)
                                        .filter(Boolean)
                                        .join(', ') || <span className="text-gray-400">direct</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** Columns by depth. Deterministic, so the picture does not move between renders. */
function layout(nodes) {
    const byDepth = {};

    return nodes.map((node) => {
        const depth = node.depth ?? 0;
        byDepth[depth] = (byDepth[depth] ?? 0) + 1;

        return { ...node, x: 60 + depth * 200, y: 40 + (byDepth[depth] - 1) * 34 };
    });
}

function riskFill(band) {
    return { critical: '#dc2626', high: '#ea580c', medium: '#ca8a04', low: '#16a34a' }[band] ?? '#94a3b8';
}

/**
 * FR-NTH-06. Every one of these is a machine's reading of a document, and
 * none of them is in the graph until somebody here says so.
 */
function Proposals({ rows, can }) {
    if (rows.length === 0) {
        return (
            <div className="card p-8 text-center text-sm text-gray-500">
                Nothing awaiting confirmation. Discovery proposes edges from DPA annexes, SOC 2 carve-outs and
                public sub-processor pages against a fixed list of known providers — a name outside that list is
                not found, so an empty list here is not proof of a short supply chain.
            </div>
        );
    }

    return (
        <div className="card divide-y divide-gray-100">
            {rows.map((row) => (
                <div key={row.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
                    <div className="min-w-0">
                        <p className="text-sm font-medium text-gray-900">
                            {row.parent} → {row.child}
                            {!row.matched && (
                                <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-800">
                                    not in the register
                                </span>
                            )}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">Read from: {row.source_label}</p>
                        {row.service_description && (
                            <p className="mt-1 text-xs italic text-gray-600">“{row.service_description}”</p>
                        )}
                    </div>
                    {can.manage && (
                        <div className="flex shrink-0 gap-2">
                            <button
                                type="button"
                                className="btn btn-sm btn-secondary"
                                onClick={() => router.post(route('tprm.concentration.edges.confirm', row.id))}
                            >
                                Confirm
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost"
                                onClick={() => router.post(route('tprm.concentration.edges.reject', row.id))}
                            >
                                Reject
                            </button>
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
