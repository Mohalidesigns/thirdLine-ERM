import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Service levels, their trend, and the service-credit register — FR-CTR-07.
 *
 * MISSING PERIODS ARE DRAWN AS GAPS, and that is the difference between this
 * and every SLA dashboard that plots the points it has. A provider who stopped
 * reporting after two bad months looks identical to a provider with a perfect
 * record on a chart that only knows about measurements it received.
 *
 * THE CREDIT REGISTER SEPARATES CLAIMED FROM RECEIVED. The gap between them
 * across a year is the part of a penalty regime that quietly stops working,
 * and a provider learns quickly which clients check.
 */
export default function Index({ engagement, slas = [], credits = {}, can = {} }) {
    return (
        <AppLayout title="Service levels">
            <Head title={`Service levels — ${engagement.reference}`} />

            <PageHeader
                title="Service levels"
                subtitle={
                    <Link className="underline" href={engagement.url}>
                        {engagement.reference} — {engagement.name}
                    </Link>
                }
            />

            <CreditRegister credits={credits} />

            {slas.length === 0 ? (
                <div className="card p-5 text-sm text-gray-500">
                    No service levels recorded for this engagement yet. Each needs a metric, a target and — the
                    part that decides whether a breach detector tells the truth — whether the target is a floor
                    or a ceiling.
                </div>
            ) : (
                <div className="space-y-4">
                    {slas.map((sla) => <SlaCard key={sla.id} sla={sla} can={can} />)}
                </div>
            )}
        </AppLayout>
    );
}

function CreditRegister({ credits }) {
    if (!credits || credits.breaches === 0) {
        return null;
    }

    const money = (minor) => (minor === null || minor === undefined
        ? '—'
        : `${credits.currency ?? ''} ${(minor / 100).toLocaleString()}`.trim());

    return (
        <div className="card mb-6 p-5">
            <h3 className="text-sm font-semibold text-gray-900">Service credits</h3>
            <div className="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Stat label="Breaches" value={credits.breaches} />
                <Stat
                    label="Nothing claimed"
                    value={credits.unclaimed}
                    hint="breaches with no claim at all"
                    tone={credits.unclaimed ? 'warn' : null}
                />
                <Stat label="Claimed" value={money(credits.claimed_minor)} />
                <Stat
                    label="Still owed"
                    value={money(credits.shortfall_minor)}
                    hint="claimed but never received"
                    tone={credits.shortfall_minor ? 'warn' : null}
                />
            </div>
        </div>
    );
}

function Stat({ label, value, hint, tone }) {
    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-xl font-semibold ${tone === 'warn' ? 'text-amber-700' : 'text-gray-900'}`}>
                {value}
            </p>
            {hint && <p className="mt-0.5 text-xs text-gray-500">{hint}</p>}
        </div>
    );
}

function SlaCard({ sla, can }) {
    const [recording, setRecording] = useState(false);

    return (
        <div className="card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-gray-900">
                        {sla.metric_name} <span className="font-mono text-xs text-gray-500">{sla.metric_code}</span>
                    </p>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Target {sla.target}, measured {sla.window}
                        {sla.contract ? ` · ${sla.contract}` : ''}
                    </p>
                </div>

                <div className="flex items-center gap-2">
                    {sla.consecutive_breaches > 0 && (
                        <span className="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                            {sla.consecutive_breaches} consecutive breach{sla.consecutive_breaches === 1 ? '' : 'es'}
                        </span>
                    )}
                    {can.manage && (
                        <button type="button" className="btn btn-secondary text-xs" onClick={() => setRecording(true)}>
                            Record a period
                        </button>
                    )}
                </div>
            </div>

            {sla.missing_periods.length > 0 && (
                <p className="mt-3 rounded bg-amber-50 p-3 text-xs text-amber-900">
                    No measurement recorded for {sla.missing_periods.join(', ')}. A missing month is not a
                    compliant month — a provider whose reporting stops looks the same as one with a perfect
                    record on any chart that draws only the points it has.
                </p>
            )}

            <Trend measurements={sla.measurements} />

            {recording && <MeasurementDialog sla={sla} onClose={() => setRecording(false)} />}
        </div>
    );
}

/**
 * A sparkline drawn from the measurements, oldest to newest.
 *
 * Deliberately plain: bars, breaching ones in red, with the value on hover.
 * A chart library for twelve numbers is a dependency for a decoration.
 */
function Trend({ measurements = [] }) {
    if (measurements.length === 0) {
        return <p className="mt-3 text-sm text-gray-500">No measurements recorded yet.</p>;
    }

    const ordered = [...measurements].reverse();
    const values = ordered.map((m) => m.actual);
    const max = Math.max(...values);
    const min = Math.min(...values);
    const span = max - min || 1;

    return (
        <div className="mt-4">
            <div className="flex h-24 items-end gap-1">
                {ordered.map((m) => {
                    const height = 12 + ((m.actual - min) / span) * 80;

                    return (
                        <div
                            key={m.id}
                            className={`flex-1 rounded-t ${m.is_breach ? 'bg-red-400' : 'bg-green-400'}`}
                            style={{ height: `${height}%` }}
                            title={`${m.period_start} to ${m.period_end}: ${m.actual}${m.is_breach ? ' (breach)' : ''}`}
                        />
                    );
                })}
            </div>
            <div className="mt-1 flex justify-between text-xs text-gray-500">
                <span>{ordered[0]?.period_start}</span>
                <span>{ordered[ordered.length - 1]?.period_end}</span>
            </div>
        </div>
    );
}

function MeasurementDialog({ sla, onClose }) {
    const form = useForm({
        period_start: '',
        period_end: '',
        actual_value: '',
        credit_claimed_minor: '',
        credit_received_minor: '',
        currency: '',
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.slas.measurements.store', sla.id), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Record a period — {sla.metric_name}</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Target {sla.target}. Whether this breaches is decided by that target and stored, so
                    renegotiating it next year will not un-breach this period.
                </p>

                <div className="mt-5 grid grid-cols-2 gap-4">
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Period start</span>
                        <input type="date" className="input mt-1" value={form.data.period_start}
                            onChange={(e) => form.setData('period_start', e.target.value)} />
                    </label>
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Period end</span>
                        <input type="date" className="input mt-1" value={form.data.period_end}
                            onChange={(e) => form.setData('period_end', e.target.value)} />
                    </label>
                </div>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Measured value {sla.unit ? `(${sla.unit})` : ''}</span>
                    <input type="number" step="any" className="input mt-1" value={form.data.actual_value}
                        onChange={(e) => form.setData('actual_value', e.target.value)} />
                </label>

                <div className="mt-4 grid grid-cols-3 gap-4">
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Credit claimed</span>
                        <input type="number" className="input mt-1" placeholder="minor units"
                            value={form.data.credit_claimed_minor}
                            onChange={(e) => form.setData('credit_claimed_minor', e.target.value)} />
                    </label>
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Credit received</span>
                        <input type="number" className="input mt-1" placeholder="minor units"
                            value={form.data.credit_received_minor}
                            onChange={(e) => form.setData('credit_received_minor', e.target.value)} />
                    </label>
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Currency</span>
                        <input type="text" maxLength={3} className="input mt-1" placeholder="NGN"
                            value={form.data.currency}
                            onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} />
                    </label>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Record</button>
                </div>
            </form>
        </div>
    );
}
