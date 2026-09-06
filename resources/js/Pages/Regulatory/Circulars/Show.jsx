import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const IMPACT_CLASSES = {
    critical: 'bg-red-100 text-red-700',
    high: 'bg-orange-100 text-orange-700',
    medium: 'bg-yellow-100 text-yellow-700',
    low: 'bg-green-100 text-green-700',
};

const humanise = (value) => (value ? String(value).replaceAll('_', ' ') : '—');

const longDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

/**
 * One circular, and this institution's compliance position against it
 * (migration Phase 5.3).
 *
 * The compliance panel carries `action_required`, which the controller has
 * always written but the Blade form never offered — so the field could only be
 * set at creation and never revised while assessing.
 *
 * Assessing is gated on `regulatory.file` through
 * RegulatoryCircularPolicy::assessCompliance(): the position recorded here is
 * what a supervisor's question is answered with, and that is not the same trust
 * as recording that the circular exists.
 */
export default function Show({ circular, affectedRisks, statuses, canAssess }) {
    const { data, setData, patch, processing, errors } = useForm({
        compliance_status: circular.compliance_status,
        compliance_pct: circular.compliance_pct ?? '',
        action_required: circular.action_required ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        patch(route('risk.regulatory.update-compliance', circular.id), { preserveScroll: true });
    };

    const circularsUrl = tryRoute('risk.regulatory.circulars');

    return (
        <AuthenticatedLayout title={circular.circular_ref}>
            <Head title={circular.circular_ref} />

            <PageHeader
                title={circular.title}
                subtitle={`${circular.regulator} · ${circular.circular_ref} · issued ${longDate(circular.date_issued)}`}
                actions={
                    circularsUrl && (
                        <Link href={circularsUrl} className="btn-secondary text-sm">
                            Back to register
                        </Link>
                    )
                }
            />

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-xs text-gray-500">Impact level</dt>
                                <dd>
                                    <span className={`badge text-[11px] ${IMPACT_CLASSES[circular.impact_level] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {circular.impact_level}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Effective date</dt>
                                <dd className="font-medium">{longDate(circular.effective_date)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Assigned to</dt>
                                <dd className="font-medium">{circular.assignee?.name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-gray-500">Compliance</dt>
                                <dd className="font-medium">
                                    {humanise(circular.compliance_status)}
                                    {circular.compliance_pct !== null && ` (${circular.compliance_pct}%)`}
                                </dd>
                            </div>
                        </dl>

                        {circular.summary && (
                            <div className="pt-4 border-t border-gray-100">
                                <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Summary</h4>
                                <p className="text-sm text-gray-700 whitespace-pre-line">{circular.summary}</p>
                            </div>
                        )}

                        {circular.action_required && (
                            <div className="pt-4 border-t border-gray-100">
                                <h4 className="text-xs font-semibold text-gray-500 uppercase mb-2">Action required</h4>
                                <p className="text-sm text-gray-700 whitespace-pre-line">{circular.action_required}</p>
                            </div>
                        )}
                    </section>

                    <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <header className="px-5 py-4 border-b border-gray-100">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Affected Risks</h3>
                            <p className="text-xs text-gray-500 mt-1">Entries in the register this circular bears on</p>
                        </header>
                        {affectedRisks.length === 0 ? (
                            <p className="px-5 py-8 text-sm text-gray-400 text-center">No risks are linked to this circular.</p>
                        ) : (
                            <ul className="divide-y divide-gray-100">
                                {affectedRisks.map((risk) => (
                                    <li key={risk.id} className="px-5 py-3 flex items-center gap-3">
                                        <span className="text-xs text-gray-400 w-28 shrink-0">{risk.risk_code}</span>
                                        <span className="text-sm text-gray-700">{risk.title}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Compliance Position</h3>
                    <p className="text-xs text-gray-500 mb-4">
                        What this institution would tell a supervisor about this circular.
                    </p>

                    {canAssess ? (
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <InputLabel htmlFor="compliance_status" value="Status" />
                                <select
                                    id="compliance_status"
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={data.compliance_status}
                                    onChange={(e) => setData('compliance_status', e.target.value)}
                                >
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {humanise(status)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.compliance_status} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="compliance_pct" value="Compliance (%)" />
                                <TextInput
                                    id="compliance_pct"
                                    type="number"
                                    min="0"
                                    max="100"
                                    className="mt-1 block w-full"
                                    value={data.compliance_pct}
                                    onChange={(e) => setData('compliance_pct', e.target.value)}
                                />
                                <InputError message={errors.compliance_pct} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="action_required" value="Action required" />
                                <textarea
                                    id="action_required"
                                    rows={5}
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={data.action_required}
                                    onChange={(e) => setData('action_required', e.target.value)}
                                />
                                <InputError message={errors.action_required} className="mt-1" />
                            </div>

                            <PrimaryButton disabled={processing} className="w-full justify-center">
                                Update position
                            </PrimaryButton>
                        </form>
                    ) : (
                        <p className="text-sm text-gray-500">
                            Recording a compliance position needs the regulatory filing permission.
                        </p>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
