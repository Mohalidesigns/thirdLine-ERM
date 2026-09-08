import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-07 — the vendor tells us about an incident.
 *
 * THE RECEIPT IS SHOWN AND KEPT, and it matters to both sides for different
 * reasons. For the bank it is the recorded start of a statutory clock; for the
 * vendor it is evidence THEY notified their controller in time. A portal that
 * takes the report and gives them nothing to keep makes itself the reason they
 * cannot answer their own regulator.
 */
export default function Incidents({ incidents = [] }) {
    const [reporting, setReporting] = useState(false);

    return (
        <PortalLayout title="Incidents">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-2xl">
                    <h1 className="text-lg font-semibold text-gray-900">Incidents</h1>
                    <p className="text-sm text-gray-600">
                        Tell us as soon as you know, not once you have finished investigating. Our regulatory
                        deadlines run from the moment you tell us, and a partial report on time is worth far more
                        than a complete one late.
                    </p>
                </div>
                <button
                    type="button"
                    className="rounded bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                    onClick={() => setReporting(true)}
                >
                    Report an incident
                </button>
            </div>

            {incidents.length === 0 ? (
                <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                    None reported.
                </div>
            ) : (
                <div className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {incidents.map((incident) => (
                        <div key={incident.uuid} className="p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="text-sm font-medium text-gray-900">
                                    <span className="mr-2 font-mono text-xs text-gray-500">{incident.reference}</span>
                                    {incident.title}
                                </span>
                                <span className="text-xs text-gray-500">Detected {incident.detected_at}</span>
                            </div>
                            <p className="mt-1 text-xs text-green-800">{incident.receipt}</p>
                            <p className="mt-1 text-xs text-gray-500">
                                {incident.personal_data_involved && 'Personal data involved. '}
                                {incident.customer_impact && 'Customers affected.'}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {reporting && <ReportDialog onClose={() => setReporting(false)} />}
        </PortalLayout>
    );
}

function ReportDialog({ onClose }) {
    const form = useForm({
        title: '', type: 'security', description: '', detected_at: '',
        severity: '', customer_impact: false, customers_affected: '',
        personal_data_involved: false, data_subjects_affected: '',
    });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form
                className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-5 shadow-xl"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm-portal.incidents.report'), { onSuccess: onClose });
                }}
            >
                <h2 className="text-base font-semibold text-gray-900">Report an incident</h2>
                <p className="mt-1 text-xs text-gray-600">
                    Send this as soon as you know. You can add detail afterwards in the thread.
                </p>

                <div className="mt-3 space-y-3">
                    <Field label="What happened" error={form.errors.title}>
                        <input
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            value={form.data.title}
                            onChange={(event) => form.setData('title', event.target.value)}
                        />
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Kind" error={form.errors.type}>
                            <select
                                className="w-full rounded border border-gray-200 p-2 text-sm"
                                value={form.data.type}
                                onChange={(event) => form.setData('type', event.target.value)}
                            >
                                <option value="security">Security</option>
                                <option value="data_breach">Data breach</option>
                                <option value="outage">Outage</option>
                                <option value="fraud">Fraud</option>
                                <option value="other">Other</option>
                            </select>
                        </Field>

                        <Field label="When you detected it" error={form.errors.detected_at}>
                            <input
                                type="datetime-local"
                                className="w-full rounded border border-gray-200 p-2 text-sm"
                                value={form.data.detected_at}
                                onChange={(event) => form.setData('detected_at', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="What you know so far" error={form.errors.description}>
                        <textarea
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            rows="4"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.personal_data_involved}
                            onChange={(event) => form.setData('personal_data_involved', event.target.checked)}
                        />
                        Personal data may be involved
                    </label>

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.customer_impact}
                            onChange={(event) => form.setData('customer_impact', event.target.checked)}
                        />
                        Our customers may be affected
                    </label>
                </div>

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded bg-red-600 px-3 py-1.5 text-sm text-white">
                        Send now
                    </button>
                </div>
            </form>
        </div>
    );
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">{label}</label>
            {children}
            {error && <p className="mt-1 text-xs text-red-700">{error}</p>}
        </div>
    );
}
