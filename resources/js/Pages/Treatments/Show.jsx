import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import KpiCard from '@/Components/KpiCard';
import PageHeader from '@/Components/PageHeader';
import RatingBadge from '@/Components/RatingBadge';
import StatusBadge from '@/Components/StatusBadge';
import { compactNaira, deadlineLabel, naira, progressTone, titleCase } from './format';

/** Migration Phase 3.5: risk/treatments/show.blade.php. */
function Card({ title, children, className = '' }) {
    return (
        <div className={`bg-white rounded-xl border border-gray-200 p-6 ${className}`}>
            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">{title}</h3>
            {children}
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

/** Approve, or reject with a reason — the panel a reviewer sees. */
function ReviewPanel({ plan }) {
    const [rejecting, setRejecting] = useState(false);
    const approval = useForm({ comments: '' });
    const rejection = useForm({ rejection_reason: '' });

    const submit = (form, routeName) => (event) => {
        event.preventDefault();
        form.post(route(routeName, plan.id), { preserveScroll: true });
    };

    return (
        <div className="mb-6 bg-white rounded-xl border border-blue-200 shadow-sm p-5">
            <div className="flex items-center gap-2 mb-3">
                <span className="material-symbols-outlined text-blue-600">rate_review</span>
                <h3 className="text-sm font-semibold text-gray-900">Review Required</h3>
            </div>
            <p className="text-sm text-gray-600 mb-4">
                Approve to finalise this treatment plan, or reject with a reason so the owner can rework.
            </p>

            {rejecting ? (
                <form onSubmit={submit(rejection, 'risk.treatments.reject')} className="space-y-3">
                    <div>
                        <label className="block text-xs font-medium text-gray-700 mb-1">
                            Reason for rejection <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            rows={3}
                            maxLength={2000}
                            value={rejection.data.rejection_reason}
                            onChange={(e) => rejection.setData('rejection_reason', e.target.value)}
                            placeholder="Explain what needs to change…"
                            className="w-full border border-red-200 rounded-lg px-3 py-2 text-sm focus:border-red-400"
                        />
                        <InputError message={rejection.errors.rejection_reason} className="mt-1" />
                    </div>
                    <div className="flex gap-2">
                        <button type="submit" disabled={rejection.processing} className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 flex items-center gap-1 disabled:opacity-50">
                            <span className="material-symbols-outlined text-sm">close</span> Confirm Rejection
                        </button>
                        <button type="button" onClick={() => setRejecting(false)} className="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50">
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <form onSubmit={submit(approval, 'risk.treatments.approve')} className="space-y-3">
                    <div>
                        <label className="block text-xs font-medium text-gray-700 mb-1">Comments (optional)</label>
                        <textarea
                            rows={2}
                            value={approval.data.comments}
                            onChange={(e) => approval.setData('comments', e.target.value)}
                            className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                        />
                        <InputError message={approval.errors.comments} className="mt-1" />
                    </div>
                    <div className="flex gap-2">
                        <button type="submit" disabled={approval.processing} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-1 disabled:opacity-50">
                            <span className="material-symbols-outlined text-sm">check</span> Approve
                        </button>
                        <button type="button" onClick={() => setRejecting(true)} className="px-4 py-2 border border-red-300 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50 flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">close</span> Reject
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

/** A bare POST with no body — submit for review, return to draft. */
function ActionButton({ routeName, planId, icon, children, className }) {
    const { post, processing } = useForm({});

    return (
        <button
            type="button"
            disabled={processing}
            onClick={() => post(route(routeName, planId), { preserveScroll: true })}
            className={`${className} disabled:opacity-50`}
        >
            <span className="material-symbols-outlined text-sm">{icon}</span> {children}
        </button>
    );
}

export default function Show({ plan, can = {} }) {
    const deadline = deadlineLabel(plan.daysRemaining);
    const overspent = plan.actualSpend > plan.budget;

    return (
        <AuthenticatedLayout title={plan.title}>
            <Head title={plan.title} />

            <PageHeader
                title={plan.title}
                subtitle={`Treatment plan for ${plan.risk?.code ?? 'N/A'} · Created ${plan.createdAt ?? '-'}`}
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('risk.treatments.index') },
                    { label: plan.code ?? plan.title },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.treatments.edit', plan.id)} className="btn-secondary inline-flex items-center gap-2 text-sm">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        <Link href={route('risk.treatments.index')} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-lg">arrow_back</span> Back
                        </Link>
                    </>
                }
            />

            <div className="flex flex-wrap items-center gap-3 mb-6">
                <StatusBadge status={plan.status} />
                <RatingBadge rating={plan.priority ?? 'medium'} />
            </div>

            {plan.status === 'pending_review' && (can.approve ? (
                <ReviewPanel plan={plan} />
            ) : (
                <div className="mb-6 bg-yellow-50 border border-yellow-200 rounded-xl p-4 flex items-start gap-3">
                    <span className="material-symbols-outlined text-yellow-600">hourglass_empty</span>
                    <div>
                        <p className="text-sm font-semibold text-yellow-800">Pending Approver Review</p>
                        <p className="text-xs text-yellow-700 mt-1">This plan is awaiting review by a risk manager or CRO.</p>
                    </div>
                </div>
            ))}

            {plan.status === 'rejected' && (
                <div className="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
                    <div className="flex items-start gap-3 mb-3">
                        <span className="material-symbols-outlined text-red-600">block</span>
                        <div className="flex-1">
                            <p className="text-sm font-semibold text-red-800">Plan Rejected</p>
                            {plan.rejectionReason && (
                                <p className="text-xs text-red-700 mt-1 whitespace-pre-line">
                                    <strong>Reason:</strong> {plan.rejectionReason}
                                </p>
                            )}
                        </div>
                    </div>
                    {can.resubmit && (
                        <ActionButton
                            routeName="risk.treatments.resubmit"
                            planId={plan.id}
                            icon="refresh"
                            className="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] inline-flex items-center gap-1"
                        >
                            Return to Draft for rework
                        </ActionButton>
                    )}
                </div>
            )}

            {['draft', 'not_started'].includes(plan.status) && can.submit && (
                <div className="mb-6 bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between gap-4">
                    <div>
                        <p className="text-sm font-semibold text-blue-800">Ready for review?</p>
                        <p className="text-xs text-blue-700 mt-0.5">Submit this plan to notify approvers.</p>
                    </div>
                    <ActionButton
                        routeName="risk.treatments.submit"
                        planId={plan.id}
                        icon="send"
                        className="px-4 py-2 bg-[#1A365D] text-white rounded-lg text-sm font-medium hover:bg-[#2D4A7A] inline-flex items-center gap-1 shrink-0"
                    >
                        Submit for Review
                    </ActionButton>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Overall Progress" value={`${plan.progress}%`} icon="speed" color="primary" />
                <KpiCard title="Budget Allocated" value={compactNaira(plan.budget)} icon="account_balance" color="warning" />
                <KpiCard title="Actual Spend" value={compactNaira(plan.actualSpend)} icon="payments" color={overspent ? 'danger' : 'success'} />
                <KpiCard title="Days Remaining" value={deadline.value} icon="schedule" color={deadline.color} />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="lg:col-span-2 space-y-6">
                    <Card title="Plan Description">
                        <p className="text-sm text-gray-700 leading-relaxed whitespace-pre-line">
                            {plan.description || 'No description provided.'}
                        </p>
                    </Card>

                    <Card title="Progress Tracking">
                        <div className="mb-4">
                            <div className="flex justify-between text-xs text-gray-600 mb-2">
                                <span>Progress</span>
                                <span>{plan.progress}%</span>
                            </div>
                            <div className="w-full bg-gray-200 rounded-full h-3">
                                <div
                                    className={`h-3 rounded-full transition-all duration-500 ${progressTone(plan.progress)}`}
                                    style={{ width: `${plan.progress}%` }}
                                />
                            </div>
                        </div>
                        {plan.progressNotes && <p className="text-xs text-gray-500 mt-2 whitespace-pre-line">{plan.progressNotes}</p>}
                    </Card>

                    <Card title="Milestones">
                        {plan.milestones.length === 0 ? (
                            <div className="text-center py-6 text-gray-400 text-sm">
                                <span className="material-symbols-outlined text-2xl mb-1 block">flag</span>
                                No milestones defined
                            </div>
                        ) : (
                            plan.milestones.map((milestone, index) => (
                                <div key={index} className="flex items-center gap-4 p-3 border-b border-gray-100 last:border-0">
                                    <span className={`material-symbols-outlined ${milestone.completed ? 'text-green-500' : 'text-gray-300'}`}>
                                        {milestone.completed ? 'check_circle' : 'radio_button_unchecked'}
                                    </span>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-gray-700">{milestone.title}</p>
                                        {(milestone.due_date || milestone.responsible) && (
                                            <p className="text-xs text-gray-500 mt-0.5">
                                                {[milestone.due_date, milestone.responsible].filter(Boolean).join(' · ')}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                    </Card>

                    <Card title="Cost vs Budget">
                        <div className="space-y-3">
                            {[
                                { label: 'Budget', value: plan.budget, color: '#1A365D' },
                                { label: 'Actual Spend', value: plan.actualSpend, color: overspent ? '#C53030' : '#2D7D46' },
                            ].map((bar) => (
                                <div key={bar.label}>
                                    <div className="flex items-center justify-between text-xs mb-1">
                                        <span className="text-gray-600">{bar.label}</span>
                                        <span className="font-semibold text-gray-800">{naira(bar.value)}</span>
                                    </div>
                                    <div className="h-2.5 rounded-full bg-gray-100 overflow-hidden">
                                        <div
                                            className="h-full rounded-full transition-all duration-500"
                                            style={{
                                                width: `${(bar.value / Math.max(1, plan.budget, plan.actualSpend)) * 100}%`,
                                                backgroundColor: bar.color,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                            {overspent && (
                                <p className="text-xs text-red-600 font-medium">
                                    Over budget by {naira(plan.actualSpend - plan.budget)}.
                                </p>
                            )}
                        </div>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card title="Plan Information">
                        <dl className="space-y-3">
                            <Row label="Strategy">{titleCase(plan.strategy)}</Row>
                            <Row label="Priority"><RatingBadge rating={plan.priority ?? 'medium'} /></Row>
                            <Row label="Owner">{plan.owner ?? '-'}</Row>
                            <Row label="Target Date">{plan.targetDate ?? '-'}</Row>
                            <Row label="Completion Date">{plan.completionDate ?? '-'}</Row>
                            {plan.residual && <Row label="Expected Residual"><RatingBadge rating={plan.residual} /></Row>}
                        </dl>
                    </Card>

                    <Card title="Linked Risk">
                        {plan.risk ? (
                            <Link href={plan.risk.url} className="block p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                                <p className="text-sm font-semibold text-[#1A365D]">{plan.risk.code}</p>
                                <p className="text-xs text-gray-600 mt-1">{plan.risk.title}</p>
                                <div className="flex items-center gap-2 mt-2">
                                    <RatingBadge rating={plan.risk.rating} />
                                    <StatusBadge status={plan.risk.status ?? 'open'} />
                                </div>
                            </Link>
                        ) : (
                            <p className="text-sm text-gray-400">No linked risk</p>
                        )}
                    </Card>

                    <Card title="Dependencies">
                        <p className="text-sm text-gray-600 whitespace-pre-line">{plan.dependencies || 'No dependencies defined.'}</p>
                    </Card>

                    <Card title="Success Criteria">
                        <p className="text-sm text-gray-600 whitespace-pre-line">{plan.successCriteria || 'No success criteria defined.'}</p>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
