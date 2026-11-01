import { Fragment, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import TrendChart from '@thirdline/ui/Components/TrendChart';
import { withUnit } from '@/lib/units';
import { BandBadge } from '@/Components/Kri/Badges';
import RedBandSection, { emptyRedBand, redBandPayload, useRedBandHost, validateRedBand } from '@/Components/Kri/RedBandSection';
import { bandFor, legendRows } from '@/lib/kri-band';
import { formatDay, lagosToday } from '@/lib/kri-format';

const BAND = {
    red: 'bg-red-100 text-red-700',
    amber: 'bg-yellow-100 text-yellow-700',
    yellow: 'bg-yellow-100 text-yellow-700',
    green: 'bg-green-100 text-green-700',
};

const BREACH_STATUS = {
    open: 'bg-red-100 text-red-700',
    acknowledged: 'bg-yellow-100 text-yellow-700',
    resolved: 'bg-green-100 text-green-700',
    false_positive: 'bg-gray-100 text-gray-600',
};

function titleCase(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ') : '—';
}

function Card({ title, actions, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                {actions}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function Row({ label, children }) {
    return (
        <div className="flex justify-between items-center gap-3">
            <dt className="text-xs text-gray-500">{label}</dt>
            <dd className="text-xs font-medium text-right">{children}</dd>
        </div>
    );
}

/** Enter a reading. A panel rather than a page - three fields, and the red-band explanation when the value is red. */
function RecordMeasurement({ kri, bandRule, openKriIssue, ownerOptions, redBandDefaults, onClose }) {
    const today = lagosToday();
    const formRef = useRef(null);
    const { data, setData, post, processing, errors, transform } = useForm({
        measurement_date: today,
        value: '',
        notes: '',
    });
    const [redValues, setRedValues] = useState(() => emptyRedBand({ owner_id: openKriIssue ? '' : (redBandDefaults?.owner_id ?? '') }));
    const [clientErrors, setClientErrors] = useState({});

    // The rule was built for ONE period (A1.2). A live band is honest only while the
    // date lies inside it; for any other date the server's 422 is the authority.
    const period = bandRule?.period ?? null;
    const inPeriod = Boolean(period && data.measurement_date >= period.start_date && data.measurement_date <= period.end_date);
    const hasValue = data.value !== '' && Number.isFinite(Number(data.value));
    const band = inPeriod && hasValue ? bandFor(bandRule, data.value) : null;
    const isRed = band === 'red';

    const serverErrors = {
        root_cause: errors['red_band.root_cause'],
        action_point: errors['red_band.action_point'],
        timeline: errors['red_band.timeline'],
        owner_id: errors['red_band.owner_id'],
        timeline_change_reason: errors['red_band.timeline_change_reason'],
    };
    const serverForced = !isRed && Object.values(serverErrors).some(Boolean);
    const redErrors = { ...clientErrors, ...Object.fromEntries(Object.entries(serverErrors).filter(([, v]) => v)) };

    const host = useRedBandHost({
        isRed,
        serverForced,
        messages: {
            enter: `${kri.code} is in the red band. Root cause, action point and timeline are now required.`,
            leave: `${kri.code} is no longer in the red band. The explanation fields are hidden. What you typed is kept.`,
            forced: 'The server reads this value as red. Explanation required.',
        },
    });
    const showSection = isRed || serverForced;

    transform((form) => ({ ...form, ...(showSection ? { red_band: redBandPayload(redValues, openKriIssue) } : {}) }));

    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus());
        }
    }, [errors]);

    const onRedChange = (field, value) => {
        setRedValues((v) => ({ ...v, [field]: value }));
        setClientErrors((e) => {
            if (!e[field]) return e;
            const { [field]: _gone, ...rest } = e;

            return rest;
        });
    };

    const submit = (e) => {
        e.preventDefault();

        if (showSection) {
            const problems = validateRedBand(redValues, { openIssue: openKriIssue, today });

            if (Object.keys(problems).length > 0) {
                setClientErrors(problems);
                requestAnimationFrame(() => formRef.current?.querySelector('[aria-invalid="true"]')?.focus());

                return;
            }
        }

        setClientErrors({});
        post(route('risk.kri.record-measurement', kri.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const input = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
    const incomplete = showSection && Object.keys(validateRedBand(redValues, { openIssue: openKriIssue, today })).length > 0;
    const star = <><span aria-hidden="true" className="text-red-500">*</span><span className="sr-only"> (required)</span></>;

    let badge;

    if (!hasValue) badge = <span className="text-xs text-gray-600">Enter a value to see the band</span>;
    else if (!inPeriod) badge = <span className="text-xs text-gray-600">Band will be checked when you record this date</span>;
    else badge = <BandBadge band={band} />;

    return (
        <form ref={formRef} onSubmit={submit} noValidate className="mb-6 bg-white rounded-xl border border-blue-200 p-5">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Record a Measurement</h3>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div>
                    <label htmlFor="rm-date" className="block text-xs font-medium text-gray-600 mb-1">Measurement Date {star}</label>
                    <input id="rm-date" type="date" required aria-required="true" value={data.measurement_date} onChange={(e) => setData('measurement_date', e.target.value)}
                        aria-invalid={errors.measurement_date ? 'true' : undefined} aria-describedby={errors.measurement_date ? 'rm-date-error' : undefined} className={input} />
                    <InputError message={errors.measurement_date} id="rm-date-error" className="mt-1" />
                </div>
                <div>
                    <label htmlFor="rm-value" className="block text-xs font-medium text-gray-600 mb-1">
                        Value {star}{kri.unit ? ` (${kri.unit})` : ''}
                    </label>
                    <input id="rm-value" type="number" step="0.0001" required aria-required="true" value={data.value}
                        onChange={(e) => setData('value', e.target.value)}
                        onKeyDown={(e) => host.onValueKeyDown(e, incomplete)}
                        onBlur={host.flush}
                        aria-invalid={errors.value ? 'true' : undefined} aria-describedby={['rm-band', errors.value ? 'rm-value-error' : null].filter(Boolean).join(' ')} className={input} />
                    <div id="rm-band" className="mt-1">{badge}</div>
                    <InputError message={errors.value} id="rm-value-error" className="mt-1" />
                </div>
                <div>
                    <label htmlFor="rm-notes" className="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <input id="rm-notes" type="text" value={data.notes} onChange={(e) => setData('notes', e.target.value)}
                        aria-invalid={errors.notes ? 'true' : undefined} aria-describedby={errors.notes ? 'rm-notes-error' : undefined} className={input} />
                    <InputError message={errors.notes} id="rm-notes-error" className="mt-1" />
                </div>
            </div>

            {host.liveRegion}
            {showSection && (
                <RedBandSection
                    idPrefix="rm-redband"
                    title="This reading is in the red band: explain it"
                    context="reading"
                    values={redValues}
                    onChange={onRedChange}
                    errors={redErrors}
                    openIssue={openKriIssue}
                    ownerOptions={ownerOptions}
                    lagosToday={today}
                    sectionRef={host.sectionRef}
                />
            )}

            <p className="text-xs text-gray-600 mt-3">
                The reading is filed against the period its date falls in, and read against the limits that were in force
                then - so a correction to an old month is judged by that month&apos;s thresholds, not today&apos;s.
                {period && !inPeriod && ` The band shown applies to ${period.label} only.`}
            </p>
            <div className="flex justify-end gap-2 mt-4">
                <button type="button" onClick={onClose} className="btn-secondary text-sm">Cancel</button>
                <button type="submit" disabled={processing} aria-busy={processing} className="btn-primary text-sm disabled:opacity-50">{processing ? 'Recording\u2026' : 'Record'}</button>
            </div>
        </form>
    );
}

/** The explanation beneath a red reading: root cause (three lines, then Show more) and the Issue it raised. */
function Explanation({ reading }) {
    const [open, setOpen] = useState(false);
    const text = reading.root_cause ?? '';
    const long = text.length > 220 || text.split('\n').length > 3;

    if (!text && !reading.issue) return <p><span className="font-medium">Explanation:</span> No explanation recorded.</p>;

    return (
        <div className="space-y-1">
            {text && (
                <p className={open ? 'whitespace-pre-line' : 'line-clamp-3 whitespace-pre-line'}>
                    <span className="font-medium">Explanation: </span>{text}
                </p>
            )}
            {text && long && (
                <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="text-[#1A365D] underline">
                    {open ? 'Show less' : 'Show more'}
                </button>
            )}
            {reading.issue && (
                <p>
                    <span className="font-medium">Issue </span>
                    {reading.issue.url
                        ? <Link href={reading.issue.url} className="font-mono text-[#1A365D] underline">{reading.issue.reference}</Link>
                        : <span className="font-mono">{reading.issue.reference}</span>}
                    {` (${String(reading.issue.status ?? '').replace(/_/g, ' ').toLowerCase()})`}
                    {reading.issue.due_date && ` due ${formatDay(reading.issue.due_date)}`}
                </p>
            )}
        </div>
    );
}

/** Migration Phase 4.1: risk/kri/show.blade.php. */
export default function Show({ kri, history = {}, readings = {}, breaches = [], can = {}, bandRule = null, openKriIssue = null, ownerOptions = [], redBandDefaults = {} }) {
    const [recording, setRecording] = useState(false);

    const points = (history.points ?? []).map((point) => ({ month: point.period, value: point.value ?? 0 }));
    const hasReadings = (history.points ?? []).some((point) => point.value !== null);

    return (
        <AuthenticatedLayout title={kri.name}>
            <Head title={kri.name} />

            <PageHeader
                title={kri.name}
                subtitle={`${kri.code}${kri.risk ? ` · monitors ${kri.risk.code}` : ''}`}
                breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: kri.code ?? kri.name }]}
                actions={
                    <>
                        {can.recordMeasurement && !recording && (
                            <button type="button" onClick={() => setRecording(true)} className="btn-primary inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">add_chart</span> Record Measurement
                            </button>
                        )}
                        {can.update && (
                            <Link href={route('risk.kri.edit', kri.id)} className="btn-secondary inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                    </>
                }
            />

            {recording && <RecordMeasurement kri={kri} bandRule={bandRule} openKriIssue={openKriIssue} ownerOptions={ownerOptions} redBandDefaults={redBandDefaults} onClose={() => setRecording(false)} />}

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Current Value"
                    value={kri.currentValue === null ? '—' : withUnit(kri.currentValue, kri.unit)}
                    icon="speed"
                    color={kri.status === 'red' ? 'danger' : kri.status === 'amber' ? 'warning' : 'success'}
                    unavailable={kri.currentValue === null}
                    unavailableLabel="No reading yet"
                />
                {/* current_status defaults to green on create, so with no
                    reading it would claim a healthy indicator nobody has read. */}
                <KpiCard
                    title="Status"
                    value={titleCase(kri.status)}
                    icon="monitor_heart"
                    color={kri.status === 'red' ? 'danger' : kri.status === 'amber' ? 'warning' : 'success'}
                    unavailable={kri.currentValue === null}
                    unavailableLabel="Not yet read"
                />
                <KpiCard
                    title="Target"
                    value={kri.targetValue === null ? '—' : withUnit(kri.targetValue, kri.unit)}
                    icon="flag"
                    color="primary"
                    unavailable={kri.targetValue === null}
                    unavailableLabel="Not set"
                />
                <KpiCard title="Frequency" value={titleCase(kri.frequency)} icon="event_repeat" color="info" />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card title="Reading History">
                        {hasReadings ? (
                            <TrendChart data={points} series={[{ key: 'value', label: kri.unit || 'Value', color: '#1A365D' }]} />
                        ) : (
                            <p className="text-sm text-gray-400 py-8 text-center">No readings in the last twelve periods.</p>
                        )}
                        {(history.bands ?? []).length > 0 && (
                            <div className="flex flex-wrap items-center gap-3 mt-4 text-xs text-gray-700">
                                <span className="font-semibold">Bands in force:</span>
                                {legendRows({ direction: bandRule?.direction ?? kri.direction, bands: history.bands }, (n) => withUnit(n, kri.unit)).map((row) => (
                                    <span key={row.code} className={`badge ${BAND[row.code] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {row.name}: {row.text}
                                    </span>
                                ))}
                            </div>
                        )}
                    </Card>

                    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <div className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Readings</h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr><th>Period</th><th>Value</th><th>Band</th><th>Entered By</th><th>Recorded</th></tr>
                                </thead>
                                <tbody>
                                    {(readings.data ?? []).length === 0 && (
                                        <tr><td colSpan={5} className="text-center py-8 text-gray-400">No readings recorded yet.</td></tr>
                                    )}
                                    {(readings.data ?? []).map((reading) => (
                                        <Fragment key={reading.id}>
                                        <tr>
                                            <td className="text-xs font-medium">{reading.period ?? '—'}</td>
                                            <td className="text-sm">{withUnit(reading.value, kri.unit)}</td>
                                            <td>
                                                {reading.band
                                                    ? <span className={`badge ${BAND[reading.band] ?? 'bg-gray-100 text-gray-600'}`}>{titleCase(reading.band)}</span>
                                                    : <span className="text-xs text-gray-400">—</span>}
                                            </td>
                                            <td className="text-xs">{reading.enteredBy ?? '—'}</td>
                                            <td className="text-xs text-gray-500">{reading.recordedAt ?? '—'}</td>
                                        </tr>
                                        {reading.band === 'red' && (
                                            <tr>
                                                <td colSpan={5} className="bg-red-50 text-xs text-gray-800">
                                                    <Explanation reading={reading} />
                                                </td>
                                            </tr>
                                        )}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination links={readings.links} meta={readings.meta} />
                    </div>

                    <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Breaches</h3>
                            <Link href={route('risk.kri.breaches')} className="text-xs text-[#1A365D] font-medium hover:underline">Breach register</Link>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="data-table">
                                <thead>
                                    <tr><th>Period</th><th>Crossing</th><th>Status</th><th>Breached</th><th>Acknowledged By</th></tr>
                                </thead>
                                <tbody>
                                    {breaches.length === 0 && (
                                        <tr><td colSpan={5} className="text-center py-8 text-gray-400">No breaches raised.</td></tr>
                                    )}
                                    {breaches.map((breach) => (
                                        <tr key={breach.id}>
                                            <td className="text-xs font-medium">{breach.period ?? '—'}</td>
                                            <td className="text-xs">{titleCase(breach.from)} → {titleCase(breach.to)}</td>
                                            <td><span className={`badge ${BREACH_STATUS[breach.status] ?? 'bg-gray-100 text-gray-600'}`}>{titleCase(breach.status)}</span></td>
                                            <td className="text-xs text-gray-500">{breach.breachedAt ?? '—'}</td>
                                            <td className="text-xs">{breach.acknowledgedBy ?? '—'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="space-y-6">
                    <Card title="Definition">
                        <dl className="space-y-3">
                            <Row label="Code">{kri.code}</Row>
                            <Row label="Owner">{kri.owner ?? '—'}</Row>
                            <Row label="Unit">{kri.unit ?? '—'}</Row>
                            <Row label="Direction">{kri.direction === 'lower_worse' ? 'Lower is worse' : 'Higher is worse'}</Row>
                            <Row label="Green boundary">{kri.greenThreshold ?? 'Not set'}</Row>
                            <Row label="Red boundary">{kri.redThreshold ?? 'Not set'}</Row>
                            <Row label="Data source">{kri.dataSource || '—'}</Row>
                            <Row label="Monitored">{kri.isActive ? 'Yes' : 'No'}</Row>
                        </dl>
                    </Card>

                    {kri.description && (
                        <Card title="Description">
                            <p className="text-sm text-gray-700 whitespace-pre-line">{kri.description}</p>
                        </Card>
                    )}

                    {kri.formula && (
                        <Card title="Calculation">
                            <p className="text-sm text-gray-700 whitespace-pre-line">{kri.formula}</p>
                        </Card>
                    )}

                    <Card title="Linked Risk">
                        {kri.risk ? (
                            <Link href={kri.risk.url} className="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                                <p className="text-sm font-semibold text-[#1A365D]">{kri.risk.code}</p>
                                <p className="text-xs text-gray-600 mt-1">{kri.risk.title}</p>
                            </Link>
                        ) : (
                            <p className="text-sm text-gray-400">Not linked to a risk</p>
                        )}
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
