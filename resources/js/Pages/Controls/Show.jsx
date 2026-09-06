import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DynamicDetail from '@thirdline/ui/Components/DynamicDetail';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import { humanise } from './ControlForm';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

function Panel({ title, actions, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 mb-6">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                {actions}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

/** Migration Phase 3.4: risk/controls/show.blade.php. */
export default function Show({
    control,
    linkedRisks = [],
    linkableRisks = [],
    tests = [],
    configuredDetail = null,
    can = {},
}) {
    const [linking, setLinking] = useState(false);

    const linkForm = useForm({ risk_id: '', weight: '', rationale: '', is_key_control: false });

    const submitLink = (e) => {
        e.preventDefault();
        linkForm.post(route('risk.controls.link-risk', control.id), {
            onSuccess: () => {
                linkForm.reset();
                setLinking(false);
            },
        });
    };

    const unlink = (riskId, riskCode) => {
        if (window.confirm(`Unlink ${control.control_code} from ${riskCode}? The risk's residual score will be recalculated.`)) {
            router.delete(route('risk.controls.unlink-risk', [control.id, riskId]));
        }
    };

    const destroy = () => {
        if (window.confirm(`Delete ${control.control_code}? This cannot be undone.`)) {
            router.delete(route('risk.controls.destroy', control.id));
        }
    };

    const newTest = tryRoute('risk.control-tests.create', { control_id: control.id });

    return (
        <AuthenticatedLayout title={control.control_code}>
            <Head title={`${control.control_code} - Control`} />

            <PageHeader
                title={control.control_code}
                subtitle={control.name}
                breadcrumbs={[
                    { label: 'Controls', href: route('risk.controls.index') },
                    { label: control.control_code },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.controls.edit', control.id)} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        {can.delete && (
                            <button type="button" onClick={destroy} className="btn-secondary text-sm inline-flex items-center gap-2 text-red-600">
                                <span className="material-symbols-outlined text-lg">delete</span> Delete
                            </button>
                        )}
                        <Link href={route('risk.controls.index')} className="btn-secondary text-sm">Back</Link>
                    </>
                }
            />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    icon="verified"
                    title="Effectiveness"
                    value={`${control.effectiveness_percent}%`}
                    subtitle={control.effectiveness_label}
                    unavailable={control.effectiveness_percent === null}
                    unavailableLabel="Not rated"
                />
                <KpiCard
                    icon="fact_check"
                    title="Tests Passed"
                    value={`${control.tests_passed_count ?? 0}/${control.total_tests_count ?? 0}`}
                    subtitle={control.last_test_result ? `Last: ${humanise(control.last_test_result)}` : 'No result yet'}
                    unavailable={!control.total_tests_count}
                    unavailableLabel="Never tested"
                />
                <KpiCard
                    icon="event"
                    title="Next Test Due"
                    value={shortDate(control.next_test_due)}
                    subtitle={control.last_test_date ? `Last tested ${shortDate(control.last_test_date)}` : 'Never tested'}
                    unavailable={!control.next_test_due}
                    unavailableLabel="Not scheduled"
                />
                <KpiCard
                    icon="link"
                    title="Risks Mitigated"
                    value={linkedRisks.length}
                    subtitle={humanise(control.control_type) || 'Untyped'}
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Control Definition</h3>
                    <p className="text-sm text-gray-700 whitespace-pre-line mb-5">{control.description}</p>

                    {(configuredDetail?.sections?.length ?? 0) > 0 && (
                        <div className="pt-5 border-t border-gray-100">
                            <h4 className="text-sm font-semibold text-gray-700 mb-4">Additional Information</h4>
                            <DynamicDetail detail={configuredDetail} />
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Attributes</h3>
                    <dl className="space-y-3 text-sm">
                        {[
                            ['Type', humanise(control.control_type) || '-'],
                            ['Nature', humanise(control.control_nature) || '-'],
                            ['Frequency', humanise(control.frequency) || '-'],
                            ['Owner', control.owner ?? '-'],
                            ['Business unit', control.business_unit ?? '-'],
                        ].map(([label, value]) => (
                            <div key={label} className="flex justify-between gap-4">
                                <dt className="text-gray-500">{label}</dt>
                                <dd className="text-gray-800 font-medium text-right">{value}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-500">Status</dt>
                            <dd><StatusBadge status={control.status} /></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <Panel
                title="Risks This Control Mitigates"
                actions={
                    can.linkRisk && (
                        <button type="button" onClick={() => setLinking(!linking)} className="btn-secondary text-xs inline-flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">link</span>
                            {linking ? 'Cancel' : 'Link a risk'}
                        </button>
                    )
                }
            >
                {linking && can.linkRisk && (
                    <form onSubmit={submitLink} className="mb-5 p-4 border border-gray-200 rounded-lg bg-gray-50 space-y-3">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-semibold text-gray-600 mb-1">
                                    Risk <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={linkForm.data.risk_id}
                                    onChange={(e) => linkForm.setData('risk_id', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
                                >
                                    <option value="">Select a risk…</option>
                                    {linkableRisks.map((risk) => (
                                        <option key={risk.id} value={risk.id}>
                                            {risk.risk_code} — {risk.title}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={linkForm.errors.risk_id} className="mt-1" />
                                {linkableRisks.length === 0 && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        Every risk you can see is already linked to this control.
                                    </p>
                                )}
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-gray-600 mb-1">Weight (%)</label>
                                <input
                                    type="number" min="0" max="100" step="0.01" placeholder="Defaults to 1"
                                    value={linkForm.data.weight}
                                    onChange={(e) => linkForm.setData('weight', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                />
                                <InputError message={linkForm.errors.weight} className="mt-1" />
                            </div>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-gray-600 mb-1">Rationale</label>
                            <textarea
                                rows={2} maxLength={1000} placeholder="Why this control mitigates this risk…"
                                value={linkForm.data.rationale}
                                onChange={(e) => linkForm.setData('rationale', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                        </div>
                        <label className="flex items-center gap-2 text-xs text-gray-700">
                            <input
                                type="checkbox"
                                checked={linkForm.data.is_key_control}
                                onChange={(e) => linkForm.setData('is_key_control', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            Mark as a key control for this risk
                        </label>
                        <div className="flex justify-end">
                            <button type="submit" disabled={linkForm.processing} className="btn-primary text-xs inline-flex items-center gap-1 disabled:opacity-50">
                                <span className="material-symbols-outlined text-sm">link</span> Link
                            </button>
                        </div>
                    </form>
                )}

                {linkedRisks.length === 0 ? (
                    <p className="text-sm text-gray-500">
                        This control is not mapped to any risk, so it contributes to no residual score.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>Risk</th>
                                    <th>Inherent</th>
                                    <th>Residual</th>
                                    <th>Weight</th>
                                    <th>Key</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {linkedRisks.map((risk) => (
                                    <tr key={risk.id}>
                                        <td className="text-sm">
                                            <span className="font-medium text-[#1A365D]">{risk.risk_code}</span>
                                            <p className="text-xs text-gray-500">{risk.title}</p>
                                        </td>
                                        <td>{risk.inherent_rating ? <RatingBadge rating={risk.inherent_rating} /> : <span className="text-xs text-gray-400">—</span>}</td>
                                        <td>{risk.residual_rating ? <RatingBadge rating={risk.residual_rating} /> : <span className="text-xs text-gray-400">—</span>}</td>
                                        <td className="text-sm">{risk.control_weight}</td>
                                        <td>
                                            {risk.is_key_control ? (
                                                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">Key</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">No</span>
                                            )}
                                        </td>
                                        <td className="text-right">
                                            {can.linkRisk && (
                                                <button
                                                    type="button"
                                                    onClick={() => unlink(risk.id, risk.risk_code)}
                                                    className="text-xs text-red-600 underline"
                                                >
                                                    Unlink
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Panel
                title="Testing History"
                actions={
                    can.createTest && newTest && (
                        <a href={newTest} className="btn-primary text-xs inline-flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">add</span> Schedule a test
                        </a>
                    )
                }
            >
                {tests.length === 0 ? (
                    <EmptyState
                        icon="fact_check"
                        title="This control has never been tested."
                        description="An untested control has no evidence behind its effectiveness rating."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Type</th>
                                    <th>Scheduled</th>
                                    <th>Tester</th>
                                    <th>Result</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tests.map((test) => (
                                    <tr key={test.id}>
                                        <td className="text-sm">
                                            <a href={route('risk.control-tests.show', test.id)} className="font-medium text-[#1A365D] underline">
                                                {test.test_code}
                                            </a>
                                            <p className="text-xs text-gray-500">{test.title}</p>
                                        </td>
                                        <td className="text-sm">{humanise(test.test_type)}</td>
                                        <td className="text-sm">{shortDate(test.scheduled_date)}</td>
                                        <td className="text-sm">{test.tester ?? '-'}</td>
                                        <td className="text-sm">{test.result ? humanise(test.result) : <span className="text-gray-400">—</span>}</td>
                                        <td><StatusBadge status={test.status} /></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </AuthenticatedLayout>
    );
}
