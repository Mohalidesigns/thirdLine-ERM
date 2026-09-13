import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import { naira, progressTone, strategyTone, titleCase } from './format';

/**
 * Plans pending review (migration Phase 3.5: risk/treatments/review.blade.php).
 *
 * The Blade page posted Approve and Reject as bare forms with no body. Reject
 * has always REQUIRED a reason server-side, so that button could only ever
 * return a validation error — the reason box below is what makes it work. The
 * approve comment box is the same one the plan's own page offers.
 */
function PlanCard({ plan }) {
    const [panel, setPanel] = useState(null);

    const approval = useForm({ comments: '' });
    const rejection = useForm({ rejection_reason: '' });
    const commentary = useForm({ comment: '' });

    const close = () => setPanel(null);

    const submit = (form, routeName, event) => {
        event.preventDefault();
        form.post(route(routeName, plan.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                close();
            },
        });
    };

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-4">
            <div className="flex items-start justify-between gap-6">
                <div className="flex-1 min-w-0">
                    <div className="flex flex-wrap items-center gap-3 mb-2">
                        <Link href={plan.url} className="text-lg font-semibold text-[#1A365D] hover:underline">
                            {plan.title}
                        </Link>
                        <RatingBadge rating={plan.priority ?? 'medium'} />
                        <span className={`badge ${strategyTone(plan.strategy)}`}>{titleCase(plan.strategy)}</span>
                    </div>

                    <p className="text-sm text-gray-600 mb-3 line-clamp-3">{plan.description}</p>

                    <div className="flex flex-wrap gap-4 text-xs text-gray-500">
                        <span className="flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">link</span> Risk: {plan.riskCode ?? 'N/A'}
                        </span>
                        <span className="flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">person</span> Owner: {plan.owner ?? '-'}
                        </span>
                        <span className="flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">calendar_today</span> Target: {plan.targetDate ?? '-'}
                        </span>
                        <span className="flex items-center gap-1">
                            <span className="material-symbols-outlined text-sm">payments</span> Budget: {naira(plan.budget)}
                        </span>
                    </div>

                    <div className="flex items-center gap-3 mt-3">
                        <div className="w-40 bg-gray-200 rounded-full h-2">
                            <div className={`h-2 rounded-full ${progressTone(plan.progress)}`} style={{ width: `${plan.progress}%` }} />
                        </div>
                        <span className="text-xs text-gray-600">{plan.progress}% complete</span>
                    </div>
                </div>

                <div className="flex flex-col gap-2 shrink-0">
                    {plan.canApprove && (
                        <>
                            <button
                                type="button"
                                onClick={() => setPanel(panel === 'approve' ? null : 'approve')}
                                className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 flex items-center gap-2 transition-colors"
                            >
                                <span className="material-symbols-outlined text-lg">check_circle</span> Approve
                            </button>
                            <button
                                type="button"
                                onClick={() => setPanel(panel === 'reject' ? null : 'reject')}
                                className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 flex items-center gap-2 transition-colors"
                            >
                                <span className="material-symbols-outlined text-lg">cancel</span> Reject
                            </button>
                        </>
                    )}
                    {plan.canComment && (
                        <button
                            type="button"
                            onClick={() => setPanel(panel === 'comment' ? null : 'comment')}
                            className="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2 transition-colors"
                        >
                            <span className="material-symbols-outlined text-lg">comment</span> Comment
                        </button>
                    )}
                </div>
            </div>

            {panel === 'approve' && (
                <form onSubmit={(e) => submit(approval, 'risk.treatments.approve', e)} className="mt-4 pt-4 border-t border-gray-100">
                    <label className="block text-xs font-medium text-gray-700 mb-1">Comments (optional)</label>
                    <textarea
                        rows={2}
                        value={approval.data.comments}
                        onChange={(e) => approval.setData('comments', e.target.value)}
                        className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                    />
                    <InputError message={approval.errors.comments} className="mt-1" />
                    <div className="flex justify-end gap-2 mt-2">
                        <button type="button" onClick={close} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={approval.processing} className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                            Confirm Approval
                        </button>
                    </div>
                </form>
            )}

            {panel === 'reject' && (
                <form onSubmit={(e) => submit(rejection, 'risk.treatments.reject', e)} className="mt-4 pt-4 border-t border-gray-100">
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
                    <div className="flex justify-end gap-2 mt-2">
                        <button type="button" onClick={close} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={rejection.processing} className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                            Confirm Rejection
                        </button>
                    </div>
                </form>
            )}

            {panel === 'comment' && (
                <form onSubmit={(e) => submit(commentary, 'risk.treatments.comment', e)} className="mt-4 pt-4 border-t border-gray-100">
                    <textarea
                        rows={3}
                        value={commentary.data.comment}
                        onChange={(e) => commentary.setData('comment', e.target.value)}
                        placeholder="Add your review comments..."
                        className="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                    />
                    <InputError message={commentary.errors.comment} className="mt-1" />
                    <div className="flex justify-end gap-2 mt-2">
                        <button type="button" onClick={close} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={commentary.processing} className="btn-primary text-sm disabled:opacity-50">
                            Submit Comment
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

export default function Review({ pendingPlans = [] }) {
    return (
        <AuthenticatedLayout title="Plans Pending Review">
            <Head title="Plans Pending Review" />

            <PageHeader
                title="Plans Pending Review"
                subtitle={`${pendingPlans.length} ${pendingPlans.length === 1 ? 'plan' : 'plans'} awaiting your review and approval`}
                breadcrumbs={[
                    { label: 'Treatment Plans', href: route('risk.treatments.index') },
                    { label: 'Pending Review' },
                ]}
            />

            {pendingPlans.length === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
                    <span className="material-symbols-outlined text-4xl text-gray-300 mb-3 block">task_alt</span>
                    <p className="text-gray-500">No treatment plans pending review.</p>
                    <Link href={route('risk.treatments.index')} className="text-sm text-[#1A365D] font-medium hover:underline mt-2 inline-block">
                        View All Plans
                    </Link>
                </div>
            ) : (
                pendingPlans.map((plan) => <PlanCard key={plan.id} plan={plan} />)
            )}
        </AuthenticatedLayout>
    );
}
