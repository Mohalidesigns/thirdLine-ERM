import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, usePage, useRemember } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import Glyph from '@/Components/Kri/Glyph';
import InfoStrip from '@/Components/Kri/InfoStrip';
import { ReturnStatusBadge } from '@/Components/Kri/Badges';
import { formatDayShort, plural } from '@/lib/kri-format';

const SORTS = {
    name: 'Name (A to Z)',
    red: 'Most red first',
    overdue: 'Most overdue first',
};

/** The layout switch, shared by both layouts. Links, not buttons or tabs: each is a navigation to a different URL. */
function LayoutSwitch({ current, flatUrl }) {
    const base = 'rounded-md px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-[#1A365D]';
    const on = 'bg-[#1A365D] text-white';
    const off = 'text-[#1A365D] hover:bg-gray-100';

    return (
        <nav aria-label="Library layout" className="inline-flex rounded-lg border border-gray-300 bg-white p-1">
            {current === 'departments'
                ? <span aria-current="page" className={`${base} ${on}`}>By department</span>
                : <Link href={route('risk.kri.index')} className={`${base} ${off}`}>By department</Link>}
            {current === 'flat'
                ? <span aria-current="page" className={`${base} ${on}`}>All KRIs (flat list)</span>
                : <Link href={flatUrl} className={`${base} ${off}`}>All KRIs (flat list)</Link>}
        </nav>
    );
}

const BAR = [
    ['red', '#C53030'],
    ['amber', '#D4AF37'],
    ['green', '#2D7D46'],
    ['unbanded', '#E2E8F0'],
];

/** Decoration only: the text beside it carries every number. */
function Bar({ counts }) {
    const total = counts.kri_count;

    if (!total) return null;

    return (
        <div className="mt-2 flex h-2 w-full overflow-hidden rounded" aria-hidden="true">
            {BAR.map(([key, color]) => counts[key] > 0 && <div key={key} style={{ width: `${(counts[key] / total) * 100}%`, backgroundColor: color }} />)}
        </div>
    );
}

/** "42 KRIs, red 2, amber 1, green 7, return overdue by 3 days, 1 overdue KRI issue" - generated from the props. */
function summarySentence(tile) {
    const parts = [`${tile.kri_count} ${plural(tile.kri_count, 'KRI')}`];

    if (tile.kri_count > 0) {
        parts.push(`red ${tile.red}`, `amber ${tile.amber}`, `green ${tile.green}`);
        if (tile.unbanded > 0) parts.push(`not banded ${tile.unbanded}`);
        if (tile.not_read > 0) parts.push(`not yet read ${tile.not_read}`);
    }

    if (tile.return) {
        parts.push(tile.return.days_overdue ? `return overdue by ${tile.return.days_overdue} ${plural(tile.return.days_overdue, 'day')}` : `return ${tile.return.display_status.replace('_', ' ')}`);
    }

    if (tile.overdue_kri_issues > 0) parts.push(`${tile.overdue_kri_issues} overdue KRI ${plural(tile.overdue_kri_issues, 'issue')}`);

    return parts.join(', ');
}

function Counts({ tile }) {
    if (tile.kri_count === 0) return <p className="mt-2 text-sm text-gray-700">No KRIs assigned yet.</p>;

    return (
        <>
            <p className="mt-2 text-sm text-gray-800">
                <span className="font-semibold">{tile.kri_count}</span> {plural(tile.kri_count, 'KRI')}
            </p>
            <p className="mt-1 text-sm text-gray-800">
                Red {tile.red} {'·'} Amber {tile.amber} {'·'} Green {tile.green}
                {tile.unbanded > 0 && <> {'·'} <span title="No thresholds in force.">Not banded {tile.unbanded}</span></>}
                {tile.not_read > 0 && <> {'·'} <span title="No reading has been recorded yet.">Not yet read {tile.not_read}</span></>}
            </p>
            <Bar counts={tile} />
        </>
    );
}

function Tile({ tile, kind, byId, period }) {
    const id = `tile-${kind}-${tile.id ?? 'unassigned'}`;
    const empty = tile.kri_count === 0;
    const parent = kind === 'department' && tile.parent_id ? byId.get(tile.parent_id) : null;
    const frame = kind === 'unassigned'
        ? `border-dashed border-gray-400 ${empty ? 'bg-gray-50' : 'bg-white'}`
        : `border-gray-200 ${empty ? 'bg-gray-50' : 'bg-white'}`;

    return (
        <li className={`relative rounded-xl border p-4 hover:shadow-md focus-within:ring-2 focus-within:ring-[#1A365D] ${frame}`}>
            <h2 className="text-base font-semibold text-[#1A365D]">
                <Link
                    href={tile.url}
                    aria-describedby={id}
                    className="after:absolute after:inset-0 after:content-[''] focus:outline-none"
                >
                    {kind === 'unassigned' ? 'Unassigned' : tile.name}
                </Link>
            </h2>

            {kind === 'department' && tile.code && <p className="font-mono text-xs text-gray-600">{tile.code}</p>}
            {kind === 'department' && parent && <p className="text-xs text-gray-600">Part of {parent.name}</p>}
            {kind === 'unassigned' && <p className="text-xs text-gray-600">KRIs with no department</p>}

            {kind === 'department' && !empty && (
                <div className="mt-2">
                    {tile.return ? (
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-800">
                            <ReturnStatusBadge status={tile.return.display_status} daysOverdue={tile.return.days_overdue} />
                            {tile.return.display_status !== 'submitted' && <span>Due {formatDayShort(tile.return.due_on)}</span>}
                        </div>
                    ) : (
                        <p className="flex items-center gap-1 text-sm text-gray-700">
                            <Glyph name="info" />
                            {period ? `No return for ${period.label}` : 'No return yet'}
                            <span className="sr-only">. A return opens only for departments with KRIs due that month.</span>
                        </p>
                    )}
                </div>
            )}

            <Counts tile={tile} />

            {kind === 'department' && !empty && (
                <p className="mt-2 text-sm text-gray-800">
                    {tile.open_kri_issues === 0
                        ? 'No open KRI issues'
                        : `${tile.open_kri_issues} open KRI ${plural(tile.open_kri_issues, 'issue')}`}
                    {tile.overdue_kri_issues > 0 && (
                        <span className="font-semibold text-red-700"> {'·'} <Glyph name="warning" />{tile.overdue_kri_issues} overdue</span>
                    )}
                </p>
            )}

            {kind === 'unassigned' && (
                <p className="mt-2 text-sm text-gray-700">
                    {empty ? 'Every KRI has a department.' : 'Not counted on the submission dashboard.'}
                </p>
            )}

            <span id={id} className="sr-only">{summarySentence(tile)}</span>
        </li>
    );
}

function DepartmentsLayout({ period, departments, unassigned, scopeNote, flatUrl }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.kri.create');
    const thresholdsUrl = tryRoute('risk.kri.thresholds');
    // Component state only. NOT in the URL: search, sort and direction are grid-state
    // parameters, and ADR 0026 section 15 renders the flat layout for any of them.
    const [search, setSearch] = useRemember('', 'kri-library-search');
    const [sort, setSort] = useRemember('name', 'kri-library-sort');
    const [announce, setAnnounce] = useState('');
    const timer = useRef(null);

    const total = departments.reduce((sum, d) => sum + d.kri_count, 0) + unassigned.kri_count;
    const byId = useMemo(() => new Map(departments.map((d) => [d.id, d])), [departments]);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        const list = departments.filter((d) => !q || d.name.toLowerCase().includes(q) || (d.code ?? '').toLowerCase().includes(q));
        const byName = (a, b) => a.name.localeCompare(b.name);

        const cmp = {
            name: byName,
            red: (a, b) => b.red - a.red || byName(a, b),
            overdue: (a, b) => {
                const x = a.return?.days_overdue ?? null;
                const y = b.return?.days_overdue ?? null;

                if (x === null && y === null) return byName(a, b);
                if (x === null) return 1;
                if (y === null) return -1;

                return y - x || byName(a, b);
            },
        }[sort];

        return [...list].sort(cmp);
    }, [departments, search, sort]);

    useEffect(() => {
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            setAnnounce(search.trim() ? `${shown.length} of ${departments.length} departments shown` : '');
        }, 400);

        return () => clearTimeout(timer.current);
    }, [search, shown.length, departments.length]);

    const nothingAtAll = departments.length === 0 && unassigned.kri_count === 0;
    const noScope = departments.length === 0 && unassigned.kri_count > 0;
    const noneHaveKris = departments.length > 0 && departments.every((d) => d.kri_count === 0);

    return (
        <AuthenticatedLayout title="KRI Library">
            <Head title="KRI Library" />

            <PageHeader
                title="Key Risk Indicators Library"
                subtitle={departments.length > 0 ? `${departments.length} ${plural(departments.length, 'department')} · ${total} ${plural(total, 'indicator')}` : undefined}
                actions={
                    <>
                        {permissions.includes('kri.create') && createUrl && (
                            <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <Glyph name="add_circle" /> New KRI
                            </a>
                        )}
                        {thresholdsUrl && (
                            <a href={thresholdsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <Glyph name="tune" /> Manage Thresholds
                            </a>
                        )}
                    </>
                }
            />

            {scopeNote && <InfoStrip className="mb-4">{scopeNote}</InfoStrip>}

            <div className="mb-4 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <LayoutSwitch current="departments" flatUrl={flatUrl} />

                <div role="search" className="flex flex-1 flex-col gap-3 md:max-w-xl md:flex-row md:items-end md:justify-end">
                    <div className="flex-1">
                        <label htmlFor="kri-find-department" className="mb-1 block text-xs font-medium text-gray-700">Find a department</label>
                        <input
                            id="kri-find-department"
                            type="search"
                            autoComplete="off"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => { if (e.key === 'Escape') setSearch(''); }}
                            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D]"
                        />
                    </div>
                    <div>
                        <label htmlFor="kri-sort-departments" className="mb-1 block text-xs font-medium text-gray-700">Sort by</label>
                        <select
                            id="kri-sort-departments"
                            value={sort}
                            onChange={(e) => { setSort(e.target.value); setAnnounce(`Sorted by ${SORTS[e.target.value]}`); }}
                            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] md:w-auto"
                        >
                            {Object.entries(SORTS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            <div role="status" className="sr-only">{announce}</div>

            {period ? (
                <p className="mb-4 text-sm text-gray-700">
                    Return status shown for <strong>{period.label}</strong>. <Link href={route('risk.kri.dashboard')} className="font-medium text-[#1A365D] underline">Open the submission dashboard</Link>
                </p>
            ) : (
                <InfoStrip className="mb-4">The monthly KRI cycle hasn&apos;t opened yet. Return status will appear here after the first request goes out on the first working day of a month.</InfoStrip>
            )}

            {nothingAtAll && (
                <EmptyState
                    title="No departments or KRIs yet"
                    description="Departments come from your organisation's business units. KRIs are added with New KRI and given a department on the form."
                    actionLabel={permissions.includes('kri.create') && createUrl ? 'New KRI' : undefined}
                    actionHref={createUrl ?? undefined}
                />
            )}

            {noScope && (
                <InfoStrip className="mb-4">No departments are in your scope. You can still see KRIs that have no department.</InfoStrip>
            )}

            {noneHaveKris && (
                <InfoStrip tone="warning" className="mb-4">No KRI is assigned to a department yet. Open a KRI, choose <strong>Edit</strong>, and set its Department.</InfoStrip>
            )}

            {!nothingAtAll && (
                <>
                    {departments.length > 0 && shown.length === 0 && (
                        <div role="status" className="mb-4 flex flex-wrap items-center gap-3 text-sm text-gray-800">
                            <span>No department matches &lsquo;{search}&rsquo;.</span>
                            <button type="button" onClick={() => setSearch('')} className="btn-secondary text-sm">Clear search</button>
                        </div>
                    )}

                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                        {shown.map((d) => <Tile key={d.id} tile={d} kind="department" byId={byId} period={period} />)}
                        <Tile tile={unassigned} kind="unassigned" byId={byId} period={period} />
                    </ul>
                </>
            )}
        </AuthenticatedLayout>
    );
}

/** The flat grid: today's page, plus the layout switch. */
function FlatLayout({ total, activeBreachCount, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('risk.kri.create');
    const thresholdsUrl = tryRoute('risk.kri.thresholds');

    return (
        <AuthenticatedLayout title="KRI Library">
            <Head title="KRI Library" />

            <PageHeader
                title="Key Risk Indicators Library"
                subtitle={`${total} indicators configured · ${activeBreachCount} active breaches`}
                actions={
                    <>
                        {permissions.includes('kri.create') && createUrl && (
                            <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">add_circle</span> New KRI
                            </a>
                        )}
                        {thresholdsUrl && (
                            <a href={thresholdsUrl} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">tune</span> Manage Thresholds
                            </a>
                        )}
                    </>
                }
            />

            {/* "By department" is the bare URL: a bare URL carries no grid state, so it lands on the departments layout. */}
            <div className="mb-4"><LayoutSwitch current="flat" flatUrl={route('risk.kri.index', { layout: 'flat' })} /></div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}

/** The KRI library (migration Phase 2; departments layout: ADR 0026). */
export default function Index(props) {
    return props.layout === 'flat' ? <FlatLayout {...props} /> : <DepartmentsLayout {...props} />;
}
