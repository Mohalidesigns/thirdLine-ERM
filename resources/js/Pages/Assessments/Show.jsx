import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import GroupedBarChart from '@thirdline/ui/Components/GroupedBarChart';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RadarChart from '@thirdline/ui/Components/RadarChart';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const ucfirst = (value) => (value ? String(value).charAt(0).toUpperCase() + String(value).slice(1) : '');

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

function Panel({ title, step, actions, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 mb-6">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-[#1A365D]">
                    {title}
                    {step && <span className="font-normal text-gray-400"> · {step}</span>}
                </h3>
                {actions}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

/** Migration Phase 3.3: risk/assessments/show.blade.php. */
export default function Show({
    assessment,
    risk,
    causes = [],
    ratedControls = [],
    effectiveness = {},
    actionPlans = [],
    kris = [],
    previousAssessment = null,
    assessmentHistory = [],
    dimensionData = { labels: [], current: [], previous: [] },
    comparisonData = { labels: [], current: [], previous: [] },
    can = {},
}) {
    const [rejecting, setRejecting] = useState(false);

    const submitForm = useForm({});
    const approveForm = useForm({ comments: '' });
    const rejectForm = useForm({ rejection_reason: '' });
    const resubmitForm = useForm({});

    const riskUrl = tryRoute('risk.register.show', risk.id);

    const dimensionSeries = [
        { label: 'This assessment', values: dimensionData.current, color: '#1A365D' },
        ...(previousAssessment
            ? [{ label: 'Previous', values: dimensionData.previous, color: '#94a3b8', dashed: true }]
            : []),
    ];

    const comparisonSeries = [
        { label: 'This assessment', values: comparisonData.current, color: '#1A365D' },
        ...(previousAssessment
            ? [{ label: 'Previous', values: comparisonData.previous, color: '#94a3b8', dashed: true }]
            : []),
    ];

    return (
        <AuthenticatedLayout title={assessment.reference}>
            <Head title={`${assessment.reference} - Assessment`} />

            <PageHeader
                title={assessment.reference}
                subtitle={`${risk.risk_code} — ${risk.title}`}
                breadcrumbs={[
                    { label: 'Assessments', href: route('risk.assessments.index') },
                    { label: assessment.reference },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.assessments.edit', assessment.id)} className="btn-secondary text-sm inline-flex items-center gap-1">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        <Link href={route('risk.assessments.index')} className="btn-secondary text-sm">Back</Link>
                    </>
                }
            />

            {/* ---- Lifecycle actions ---- */}
            {can.submit && (
                <div className="mb-6 bg-white rounded-xl border border-gray-200 p-5 flex items-center justify-between gap-4">
                    <p className="text-sm text-gray-600">
                        This assessment is a {assessment.status}. Submitting it raises the review task.
                    </p>
                    <button
                        type="button"
                        onClick={() => submitForm.post(route('risk.assessments.submit', assessment.id))}
                        disabled={submitForm.processing}
                        className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50"
                    >
                        <span className="material-symbols-outlined text-lg">send</span> Submit for review
                    </button>
                </div>
            )}

            {can.decide && (
                <div className="mb-6 bg-white rounded-xl border border-blue-200 shadow-sm p-5">
                    <div className="flex items-center gap-2 mb-3">
                        <span className="material-symbols-outlined text-blue-600">rate_review</span>
                        <h3 className="text-sm font-semibold text-gray-900">Review Required</h3>
                    </div>

                    {rejecting ? (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                rejectForm.post(route('risk.assessments.reject', assessment.id));
                            }}
                            className="space-y-3"
                        >
                            <textarea
                                rows={3}
                                value={rejectForm.data.rejection_reason}
                                onChange={(e) => rejectForm.setData('rejection_reason', e.target.value)}
                                placeholder="Why is this being sent back?"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                            <InputError message={rejectForm.errors.rejection_reason} />
                            <div className="flex gap-2">
                                <button type="submit" disabled={rejectForm.processing} className="btn-primary text-sm bg-red-600 disabled:opacity-50">
                                    Confirm rejection
                                </button>
                                <button type="button" onClick={() => setRejecting(false)} className="btn-secondary text-sm">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    ) : (
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                approveForm.post(route('risk.assessments.approve', assessment.id));
                            }}
                            className="space-y-3"
                        >
                            <textarea
                                rows={2}
                                value={approveForm.data.comments}
                                onChange={(e) => approveForm.setData('comments', e.target.value)}
                                placeholder="Review comments (optional)"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                            <InputError message={approveForm.errors.comments} />
                            <div className="flex gap-2">
                                <button type="submit" disabled={approveForm.processing} className="btn-primary text-sm disabled:opacity-50">
                                    Approve — scores go onto the risk
                                </button>
                                <button type="button" onClick={() => setRejecting(true)} className="btn-secondary text-sm text-red-600">
                                    Reject
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            )}

            {assessment.status === 'rejected' && (
                <div className="mb-6 bg-red-50 border border-red-200 rounded-xl p-5">
                    <p className="text-sm font-semibold text-red-800">This assessment was sent back.</p>
                    {assessment.review_comments && (
                        <p className="text-sm text-red-700 mt-1">{assessment.review_comments}</p>
                    )}
                    {can.resubmit && (
                        <button
                            type="button"
                            onClick={() => resubmitForm.post(route('risk.assessments.resubmit', assessment.id))}
                            disabled={resubmitForm.processing}
                            className="btn-secondary text-sm mt-3 disabled:opacity-50"
                        >
                            Return to draft and edit
                        </button>
                    )}
                </div>
            )}

            {/* ---- KPI row ---- */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    icon="error"
                    title="Inherent Score"
                    value={`${assessment.overall_score}/25`}
                    subtitle={assessment.overall_rating}
                    unavailable={assessment.overall_score === null}
                />
                <KpiCard
                    icon="shield_moon"
                    title="Residual Score"
                    value={`${assessment.residual_score}/25`}
                    subtitle={
                        assessment.residual_rating
                            ? `${assessment.residual_rating}${assessment.residual_source === 'override' ? ' (override)' : ''}`
                            : null
                    }
                    unavailable={assessment.residual_score === null}
                />
                <KpiCard
                    icon="shield"
                    title="Control Effectiveness"
                    value={`${effectiveness.overall}%`}
                    subtitle={`${effectiveness.rated ?? 0} of ${effectiveness.total ?? 0} rated`}
                    unavailable={effectiveness.overall === null || effectiveness.overall === undefined}
                    unavailableLabel="Not measured"
                />
                <KpiCard
                    icon="trending_flat"
                    title="Change Since Last"
                    value={assessment.score_change > 0 ? `+${assessment.score_change}` : `${assessment.score_change}`}
                    subtitle={previousAssessment ? `Was ${previousAssessment.overall_score}` : 'First assessment'}
                    unavailable={!previousAssessment}
                    unavailableLabel="No prior assessment"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Impact Dimension Scores</h3>
                    <RadarChart labels={dimensionData.labels} series={dimensionSeries} max={5} />
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Assessment Details</h3>
                    <dl className="space-y-3 text-sm">
                        {[
                            ['Type', ucfirst((assessment.assessment_type ?? '').replace('_', ' '))],
                            ['Date', shortDate(assessment.assessment_date)],
                            ['Assessor', assessment.assessor ?? '-'],
                            ['Risk', riskUrl ? null : `${risk.risk_code} — ${risk.title}`],
                            ['Risk owner', risk.owner ?? '-'],
                            ['Likelihood', assessment.likelihood_score ?? '-'],
                            ['Aggregated impact', assessment.impact_score ?? '-'],
                            ['Treatment strategy', ucfirst(assessment.treatment_strategy) || 'None'],
                        ].map(([label, value]) =>
                            value === null && label === 'Risk' ? (
                                <div key={label} className="flex justify-between gap-4">
                                    <dt className="text-gray-500">Risk</dt>
                                    <dd className="text-gray-800 font-medium text-right">
                                        <a href={riskUrl} className="text-[#1A365D] underline">
                                            {risk.risk_code}
                                        </a>{' '}
                                        — {risk.title}
                                    </dd>
                                </div>
                            ) : (
                                <div key={label} className="flex justify-between gap-4">
                                    <dt className="text-gray-500">{label}</dt>
                                    <dd className="text-gray-800 font-medium text-right">{value}</dd>
                                </div>
                            ),
                        )}
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-500">Status</dt>
                            <dd><StatusBadge status={assessment.status} /></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <Panel title="Comparison with Previous Assessment">
                {previousAssessment ? (
                    <GroupedBarChart labels={comparisonData.labels} series={comparisonSeries} max={5} />
                ) : (
                    <p className="text-sm text-gray-500">
                        No earlier approved assessment to compare against.
                    </p>
                )}
            </Panel>

            <Panel title="Root Causes" step="step 2">
                {causes.length === 0 ? (
                    <p className="text-sm text-gray-500">No causes recorded for this risk.</p>
                ) : (
                    <ul className="space-y-3">
                        {causes.map((cause) => (
                            <li key={cause.id} className="flex items-start gap-3">
                                <span
                                    className={`material-symbols-outlined text-lg ${cause.is_primary ? 'text-[#1A365D]' : 'text-gray-300'}`}
                                >
                                    {cause.is_primary ? 'star' : 'radio_button_unchecked'}
                                </span>
                                <div>
                                    <p className="text-sm text-gray-700">{cause.description}</p>
                                    <p className="text-xs text-gray-500">
                                        {[cause.category, cause.source].filter(Boolean).join(' · ') || 'Uncategorised'}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title="Existing Controls & Effectiveness" step="steps 6–7">
                {ratedControls.length === 0 ? (
                    <p className="text-sm text-gray-500">
                        No controls were rated, so the residual score below is not derived from control assurance.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>Control</th>
                                    <th>Design</th>
                                    <th>Operating</th>
                                    <th>Effectiveness</th>
                                    <th>Weight</th>
                                    <th>Evidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ratedControls.map((rated) => (
                                    <tr key={rated.id}>
                                        <td>
                                            <span className="text-sm font-medium text-[#1A365D]">{rated.control_code}</span>
                                            {rated.is_key_control && (
                                                <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">
                                                    Key
                                                </span>
                                            )}
                                            <p className="text-xs text-gray-500">{rated.control_name}</p>
                                            {rated.finding && <p className="text-[11px] text-amber-600 mt-0.5">{rated.finding}</p>}
                                        </td>
                                        <td className="text-sm">{ucfirst((rated.design_effectiveness ?? '').replace('_', ' ')) || '-'}</td>
                                        <td className="text-sm">{ucfirst((rated.operating_effectiveness ?? '').replace('_', ' ')) || '-'}</td>
                                        <td className="text-sm font-semibold">
                                            {rated.effectiveness_pct === null ? (
                                                <span className="text-gray-400 font-normal">Not rated</span>
                                            ) : (
                                                `${rated.effectiveness_pct}%`
                                            )}
                                        </td>
                                        <td className="text-sm">{rated.control_weight}</td>
                                        <td className="text-sm text-gray-500">{rated.evidence_ref ?? '-'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Panel title="Residual Risk" step="step 8">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <p className="text-xs text-gray-500 uppercase tracking-wide">Residual</p>
                        {assessment.residual_score === null ? (
                            <p className="text-lg font-semibold text-gray-400 italic">Not derived</p>
                        ) : (
                            <p className="text-2xl font-bold text-[#1A365D] flex items-baseline gap-2">
                                {assessment.residual_score}
                                <span className="text-sm font-normal text-gray-500">
                                    (L{assessment.residual_likelihood} × I{assessment.residual_impact})
                                </span>
                                {assessment.residual_rating && <RatingBadge rating={assessment.residual_rating} />}
                            </p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-gray-500 uppercase tracking-wide">Source</p>
                        <p className="text-sm text-gray-700 mt-1">
                            {assessment.residual_source === 'override'
                                ? 'Assessor judgement, overriding the derivation'
                                : 'Derived from inherent risk and control effectiveness'}
                        </p>
                    </div>
                    {assessment.residual_justification && (
                        <div>
                            <p className="text-xs text-gray-500 uppercase tracking-wide">Justification</p>
                            <p className="text-sm text-gray-700 mt-1">{assessment.residual_justification}</p>
                        </div>
                    )}
                </div>
            </Panel>

            <Panel title="Action Plan" step="steps 10–12">
                {actionPlans.length === 0 ? (
                    <EmptyState icon="task_alt" title="No actions were raised by this assessment." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Owner</th>
                                    <th>Due</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                {actionPlans.map((plan) => (
                                    <tr key={plan.id}>
                                        <td className="text-sm">
                                            <span className="font-medium text-[#1A365D]">{plan.treatment_code}</span>
                                            <p className="text-xs text-gray-500">{plan.action_title}</p>
                                        </td>
                                        <td className="text-sm">{plan.owner ?? '-'}</td>
                                        <td className="text-sm">{shortDate(plan.target_date)}</td>
                                        <td className="text-sm">{ucfirst(plan.priority)}</td>
                                        <td><StatusBadge status={plan.status} /></td>
                                        <td className="text-sm">{plan.progress_pct ?? 0}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <Panel title="Key Risk Indicators" step="step 13">
                {kris.length === 0 ? (
                    <p className="text-sm text-gray-500">No indicators are monitoring this risk.</p>
                ) : (
                    <ul className="space-y-2">
                        {kris.map((kri) => (
                            <li key={kri.id} className="flex items-center justify-between text-sm">
                                <span>
                                    <span className="font-medium text-[#1A365D]">{kri.kri_code}</span> — {kri.kri_name}
                                </span>
                                <span className="text-gray-500">{kri.current_value ?? '—'}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {assessment.assessment_notes && (
                <Panel title="Assessment Rationale">
                    <p className="text-sm text-gray-700 whitespace-pre-line">{assessment.assessment_notes}</p>
                </Panel>
            )}

            <Panel title={`Assessment History for ${risk.risk_code ?? 'this risk'}`}>
                <div className="overflow-x-auto">
                    <table className="data-table w-full">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Inherent</th>
                                <th>Residual</th>
                                <th>Change</th>
                                <th>Assessor</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assessmentHistory.map((item) => (
                                <tr key={item.id} className={item.id === assessment.id ? 'bg-blue-50/50' : ''}>
                                    <td className="text-sm">{shortDate(item.assessment_date)}</td>
                                    <td className="text-sm">{ucfirst((item.assessment_type ?? '').replace('_', ' '))}</td>
                                    <td className="text-sm">
                                        {item.overall_score ?? '-'}
                                        {item.overall_rating && <RatingBadge rating={item.overall_rating} />}
                                    </td>
                                    <td className="text-sm">
                                        {item.residual_score ?? '-'}
                                        {item.residual_rating && <RatingBadge rating={item.residual_rating} />}
                                    </td>
                                    <td className="text-sm">
                                        {item.score_change === 0 ? (
                                            <span className="text-gray-400">—</span>
                                        ) : (
                                            <span className={item.score_change > 0 ? 'text-red-600' : 'text-green-600'}>
                                                {item.score_change > 0 ? `+${item.score_change}` : item.score_change}
                                            </span>
                                        )}
                                    </td>
                                    <td className="text-sm">{item.assessor ?? '-'}</td>
                                    <td><StatusBadge status={item.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </AuthenticatedLayout>
    );
}
