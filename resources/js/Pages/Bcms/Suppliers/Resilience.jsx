import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const TABS = [
    { key: 'continuity', label: 'Continuity' },
    { key: 'participation', label: 'Exercise participation' },
    { key: 'concentration', label: 'Concentration' },
];

/**
 * Supplier resilience — `docs/bcms/screens/supplier-resilience.md`.
 *
 * WRITES NOTHING TO THE VENDOR REGISTER ITSELF. The one write action —
 * recording an attestation — posts into TPRM's own `tp_bcp_tests`; the row
 * action is absent entirely (not disabled) for a viewer without TPRM's
 * `tprm.edit` authority.
 */
export default function Resilience({
    critical_vendor_count: criticalVendorCount = 0,
    continuity = [], chase_list: chaseList = [], participation = [], concentration = { vendors: [], tprm_analysis: null },
    can = {},
}) {
    const [tab, setTab] = useState(() => new URLSearchParams(window.location.search).get('tab') ?? 'continuity');
    const [attesting, setAttesting] = useState(null);

    const goTab = (key) => {
        setTab(key);
        const params = new URLSearchParams(window.location.search);
        params.set('tab', key);
        window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);
    };

    return (
        <AppLayout title="Supplier resilience">
            <Head title="Supplier resilience" />

            <PageHeader
                title="Supplier resilience"
                subtitle={`${criticalVendorCount} vendors are BCMS-critical — depended on by a Tier-1 or critical-service process.`}
            />

            {continuity.length === 0 ? (
                <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    No vendor currently appears as a dependency of a Tier-1 or critical-service process. Link a
                    dependency in the BIA workspace, or check that your Tier-1 processes have their dependencies mapped.
                </p>
            ) : (
                <>
                    <div role="tablist" aria-label="Supplier resilience views" className="mb-4 flex gap-1 border-b border-gray-200">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                role="tab"
                                aria-selected={tab === t.key}
                                className={`px-3 py-2 text-sm font-medium ${tab === t.key ? 'border-b-2 border-[var(--color-primary)] text-gray-900' : 'text-gray-500'}`}
                                onClick={() => goTab(t.key)}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>

                    {tab === 'continuity' && (
                        <div role="tabpanel">
                            {chaseList.length > 0 && (
                                <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                                    <p className="font-semibold">{chaseList.length} vendors' continuity evidence is overdue or missing.</p>
                                    <p className="mt-1">{chaseList.slice(0, 5).map((c) => c.vendor_label).join(', ')}{chaseList.length > 5 ? '…' : ''}</p>
                                </div>
                            )}

                            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                <table className="data-table">
                                    <caption className="sr-only">Vendor continuity</caption>
                                    <thead>
                                        <tr>
                                            <th scope="col">Vendor</th>
                                            <th scope="col">Depended on by</th>
                                            <th scope="col">Latest BCP test</th>
                                            <th scope="col">RTO achieved vs commitment</th>
                                            <th scope="col">Next due</th>
                                            <th scope="col">Evidence</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {continuity.map((row) => (
                                            <tr key={row.vendor_id}>
                                                <td className="text-sm text-gray-900">
                                                    {row.vendor_name ?? row.vendor_label ?? 'Third party no longer in the register'}
                                                    {row.evidence_ambiguous && (
                                                        <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                                                            Evidence ambiguous — {row.qualifying_engagement_count} qualifying engagements
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="text-xs text-gray-600">
                                                    {(row.depended_on_by ?? []).map((p, i) => (
                                                        <span key={i} className="mr-2">{p.name} (Tier {p.tier ?? '—'})</span>
                                                    ))}
                                                </td>
                                                <td className="text-sm">
                                                    {row.engagements.length === 0 ? (
                                                        <span className="text-red-700">No test on file</span>
                                                    ) : row.evidence_ambiguous ? (
                                                        <ul className="space-y-1">
                                                            {row.engagements.map((e) => (
                                                                <li key={e.engagement_id} className="text-xs text-gray-700">
                                                                    {e.engagement_name}: {e.latest_test ? `${e.latest_test.test_date} (${e.latest_test.outcome ?? 'no outcome'})` : 'No test on file'}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : row.engagements[0].latest_test ? (
                                                        <span>{row.engagements[0].latest_test.test_date} · {row.engagements[0].latest_test.test_type}</span>
                                                    ) : (
                                                        <span className="text-red-700">No test on file</span>
                                                    )}
                                                </td>
                                                <td className="text-xs">
                                                    {row.engagements[0]?.latest_test ? (
                                                        <span className={row.engagements[0].latest_test.rto_breach ? 'text-red-700' : 'text-gray-700'}>
                                                            {row.engagements[0].latest_test.rto_achieved_hours ?? '—'}h achieved vs {row.engagements[0].committed_rto_hours ?? '—'}h committed
                                                            {row.engagements[0].latest_test.rto_breach && ' — breach'}
                                                        </span>
                                                    ) : '—'}
                                                </td>
                                                <td className="text-xs whitespace-nowrap">
                                                    {row.engagements[0]?.latest_test?.next_due_at ? (
                                                        <span className={row.engagements[0].latest_test.is_overdue ? 'text-red-700' : 'text-amber-700'}>
                                                            {row.engagements[0].latest_test.next_due_at}
                                                        </span>
                                                    ) : '—'}
                                                </td>
                                                <td className="text-xs">
                                                    {row.engagements[0]?.latest_test?.our_participation ? 'We participated' : 'Vendor-only test'}
                                                    {row.attestation_url && (
                                                        <button type="button" className="ml-2 underline"
                                                            onClick={() => setAttesting(row)}>Record attestation</button>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {tab === 'participation' && (
                        <div role="tabpanel">
                            {participation.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                                    No vendor has been invited to participate in an exercise. Vendor participation is
                                    recorded the same way any contact is invited onto an occurrence.
                                </p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                    <table className="data-table">
                                        <thead><tr><th scope="col">Person</th><th scope="col">Occurrence</th><th scope="col">Role</th><th scope="col">Invitation</th><th scope="col">Attendance</th></tr></thead>
                                        <tbody>
                                            {participation.map((p, i) => (
                                                <tr key={i}>
                                                    <td className="text-sm text-gray-900">{p.contact_name}</td>
                                                    <td className="text-xs text-gray-600">{p.scheduled_date ?? '—'}</td>
                                                    <td className="text-xs text-gray-600">{p.role ?? '—'}</td>
                                                    <td className="text-xs text-gray-600">{p.invitation_status ?? '—'}</td>
                                                    <td className="text-xs text-gray-600">{p.attendance_status ?? '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}

                    {tab === 'concentration' && (
                        <div role="tabpanel">
                            <p className="mb-3 text-sm text-gray-600">
                                This view shows how many of our processes depend on each vendor. It does not show a
                                concentration limit or its utilisation — TPRM's concentration measure has no
                                shareholders'-funds figure behind it in this build, so no percentage is computed here.{' '}
                                <Link href={tryRoute('tprm.concentration.index')} className="underline">Open TPRM's concentration screen</Link>
                            </p>

                            {concentration.tprm_analysis === null && (
                                <p className="mb-3 text-sm text-gray-500">TPRM has not yet run a concentration analysis.</p>
                            )}

                            {concentration.vendors.length === 0 ? (
                                <p className="text-sm text-gray-500">No vendor is a dependency of more than one Tier-1/critical-service process.</p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                    <table className="data-table">
                                        <thead><tr><th scope="col">Vendor</th><th scope="col">Processes</th><th scope="col">Count</th></tr></thead>
                                        <tbody>
                                            {concentration.vendors.map((v) => (
                                                <tr key={v.vendor_id}>
                                                    <td className="text-sm text-gray-900">{v.vendor_label}</td>
                                                    <td className="text-xs text-gray-600">{v.processes.join(', ')}</td>
                                                    <td className="text-sm text-gray-700">{v.process_count}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}

            {attesting && (
                <AttestationForm row={attesting} onClose={() => setAttesting(null)} />
            )}
        </AppLayout>
    );
}

function AttestationForm({ row, onClose }) {
    const form = useForm({
        engagement_id: row.engagements[0]?.engagement_id ?? '',
        test_date: '', test_type: 'tabletop', our_participation: false,
        rto_achieved_hours: '', rpo_achieved_hours: '', outcome: '', next_due_at: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(row.attestation_url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div role="dialog" aria-modal="true" aria-label={`Record attestation for ${row.vendor_name ?? row.vendor_label}`}
            className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4">
            <form onSubmit={submit} className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                <h2 className="text-sm font-semibold text-gray-900">Record a BCP test attestation</h2>
                <p className="mt-1 text-xs text-gray-500">
                    This records a BCP test result on {row.vendor_name ?? row.vendor_label}'s engagement record in
                    Third-Party Risk Management. It is the same row TPRM's own screens show.
                </p>

                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {row.engagements.length > 1 && (
                        <FormField label="Engagement">
                            <select className="form-select" value={form.data.engagement_id}
                                onChange={(e) => form.setData('engagement_id', e.target.value)}>
                                {row.engagements.map((e) => (
                                    <option key={e.engagement_id} value={e.engagement_id}>{e.engagement_name}</option>
                                ))}
                            </select>
                        </FormField>
                    )}
                    <FormField label="Test date" error={form.errors.test_date}>
                        <input type="date" className="form-input" value={form.data.test_date}
                            onChange={(e) => form.setData('test_date', e.target.value)} />
                    </FormField>
                    <FormField label="Test type" error={form.errors.test_type}>
                        <input className="form-input" value={form.data.test_type}
                            onChange={(e) => form.setData('test_type', e.target.value)} />
                    </FormField>
                    <FormField label="Our participation">
                        <input type="checkbox" className="form-checkbox" checked={form.data.our_participation}
                            onChange={(e) => form.setData('our_participation', e.target.checked)} />
                    </FormField>
                    <FormField label="RTO achieved (hours)">
                        <input type="number" min="0" className="form-input" value={form.data.rto_achieved_hours}
                            onChange={(e) => form.setData('rto_achieved_hours', e.target.value)} />
                    </FormField>
                    <FormField label="RPO achieved (hours)">
                        <input type="number" min="0" className="form-input" value={form.data.rpo_achieved_hours}
                            onChange={(e) => form.setData('rpo_achieved_hours', e.target.value)} />
                    </FormField>
                    <FormField label="Outcome">
                        <input className="form-input" value={form.data.outcome}
                            onChange={(e) => form.setData('outcome', e.target.value)} />
                    </FormField>
                    <FormField label="Next due">
                        <input type="date" className="form-input" value={form.data.next_due_at}
                            onChange={(e) => form.setData('next_due_at', e.target.value)} />
                    </FormField>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn-secondary text-sm" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn-primary text-sm" disabled={form.processing}>Save</button>
                </div>
            </form>
        </div>
    );
}
