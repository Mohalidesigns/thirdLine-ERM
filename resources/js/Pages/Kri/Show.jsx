import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import Pagination from '@/Components/Pagination';
import TrendChart from '@/Components/TrendChart';

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

/** Enter a reading. A panel rather than a page — it is three fields. */
function RecordMeasurement({ kri, onClose }) {
    const { data, setData, post, processing, errors } = useForm({
        measurement_date: new Date().toISOString().slice(0, 10),
        value: '',
        notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.kri.record-measurement', kri.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const input = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

    return (
        <form onSubmit={submit} className="mb-6 bg-white rounded-xl border border-blue-200 p-5">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Record a Measurement</h3>
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Measurement Date <span className="text-red-500">*</span></label>
                    <input type="date" value={data.measurement_date} onChange={(e) => setData('measurement_date', e.target.value)} className={input} />
                    <InputError message={errors.measurement_date} className="mt-1" />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">
                        Value <span className="text-red-500">*</span>{kri.unit ? ` (${kri.unit})` : ''}
                    </label>
                    <input type="number" step="0.0001" value={data.value} onChange={(e) => setData('value', e.target.value)} className={input} />
                    <InputError message={errors.value} className="mt-1" />
                </div>
                <div>
                    <label className="block text-xs font-medium text-gray-600 mb-1">Notes</label>
                    <input type="text" value={data.notes} onChange={(e) => setData('notes', e.target.value)} className={input} />
                    <InputError message={errors.notes} className="mt-1" />
                </div>
            </div>
            <p className="text-xs text-gray-500 mt-3">
                The reading is filed against the period its date falls in, and read against the limits that were in force
                then — so a correction to an old month is judged by that month's thresholds, not today's.
            </p>
            <div className="flex justify-end gap-2 mt-4">
                <button type="button" onClick={onClose} className="btn-secondary text-sm">Cancel</button>
                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">Record</button>
            </div>
        </form>
    );
}

/** Migration Phase 4.1: risk/kri/show.blade.php. */
export default function Show({ kri, history = {}, readings = {}, breaches = [], can = {} }) {
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

            {recording && <RecordMeasurement kri={kri} onClose={() => setRecording(false)} />}

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    title="Current Value"
                    value={kri.currentValue === null ? '—' : `${kri.currentValue}${kri.unit ?? ''}`}
                    icon="speed"
                    color={kri.status === 'red' ? 'danger' : kri.status === 'amber' ? 'warning' : 'success'}
                    unavailable={kri.currentValue === null}
                    unavailableLabel="No reading yet"
                />
                <KpiCard title="Status" value={titleCase(kri.status)} icon="monitor_heart" color={kri.status === 'red' ? 'danger' : kri.status === 'amber' ? 'warning' : 'success'} />
                <KpiCard
                    title="Target"
                    value={kri.targetValue === null ? '—' : `${kri.targetValue}${kri.unit ?? ''}`}
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
                            <div className="flex flex-wrap items-center gap-3 mt-4 text-xs text-gray-600">
                                <span className="font-semibold">Bands in force:</span>
                                {history.bands.map((band) => (
                                    <span key={band.code} className={`badge ${BAND[band.code] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {titleCase(band.code)}
                                        {band.min !== null && band.min !== undefined ? ` ≥ ${band.min}` : ''}
                                        {band.max !== null && band.max !== undefined ? ` ≤ ${band.max}` : ''}
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
                                        <tr key={reading.id}>
                                            <td className="text-xs font-medium">{reading.period ?? '—'}</td>
                                            <td className="text-sm">{reading.value ?? '—'}{kri.unit ?? ''}</td>
                                            <td>
                                                {reading.band
                                                    ? <span className={`badge ${BAND[reading.band] ?? 'bg-gray-100 text-gray-600'}`}>{titleCase(reading.band)}</span>
                                                    : <span className="text-xs text-gray-400">—</span>}
                                            </td>
                                            <td className="text-xs">{reading.enteredBy ?? '—'}</td>
                                            <td className="text-xs text-gray-500">{reading.recordedAt ?? '—'}</td>
                                        </tr>
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
