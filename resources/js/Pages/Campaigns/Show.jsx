import { useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { ProgressBar } from './Dashboard';
import { INPUT, SELECT, titleCase } from './format';

/**
 * A campaign and its assignments (Phase 4.5: risk/campaigns/show.blade.php).
 *
 * The business unit and user pick-lists below now come from the controller.
 * The Blade template queried both INSIDE ITSELF —
 * `\App\Models\BusinessUnit::where(...)->get()` in the middle of the markup —
 * which is a query nothing on the server side could see, scope or test.
 */
function ReviewPanel({ assignment, onClose }) {
    const { data, setData, post, processing, errors, reset, transform } = useForm({ reviewer_notes: '' });

    // Which button was pressed. A ref rather than form state because setData is
    // asynchronous: setting `action` and posting in the same handler races, and
    // a Reject that posts action:'approve' accepts the submission it was meant
    // to return. Same reason Rcsa/Worksheet.jsx keeps its intent in a ref.
    // (`post(url, { data })` cannot carry it either — Inertia's router sets
    // `data` after spreading the options, so an options.data is discarded.)
    const action = useRef('approve');

    transform((form) => ({ ...form, action: action.current }));

    const act = (intent) => (e) => {
        e.preventDefault();
        action.current = intent;
        post(assignment.reviewUrl, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <div className="bg-gray-50 border-t border-gray-100 px-5 py-4">
            <InputLabel htmlFor={`notes-${assignment.id}`} className="text-xs">
                Reviewer notes
            </InputLabel>
            {/*
              * The Blade page's Reject button posted no notes field at all, so
              * the only screen most reviewers used could not attach a reason —
              * even though the controller has always accepted one and the
              * rejection notification has always quoted it back to the
              * respondent. A rejection is a request for rework; this is where
              * the respondent reads why.
              */}
            <textarea
                id={`notes-${assignment.id}`}
                rows={2}
                value={data.reviewer_notes}
                onChange={(e) => setData('reviewer_notes', e.target.value)}
                className={INPUT}
                placeholder="Why you are approving or returning this submission"
            />
            <InputError message={errors.reviewer_notes} className="mt-1" />

            <div className="flex items-center gap-2 mt-3">
                <button
                    type="button"
                    onClick={act('approve')}
                    disabled={processing}
                    className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700 disabled:opacity-50"
                >
                    Approve
                </button>
                <button
                    type="button"
                    onClick={act('reject')}
                    disabled={processing}
                    className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium hover:bg-red-700 disabled:opacity-50"
                >
                    Return for rework
                </button>
                <button type="button" onClick={onClose} className="btn-secondary text-sm">Cancel</button>
            </div>
        </div>
    );
}

function AddAssignment({ campaign, businessUnits, users }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        business_unit_id: '',
        respondent_id: '',
        reviewer_id: '',
        due_date: campaign.dueDateValue ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.campaigns.add-assignment', campaign.id), {
            preserveScroll: true,
            onSuccess: () => reset('business_unit_id', 'respondent_id', 'reviewer_id'),
        });
    };

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mt-6">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Add Assignment</h3>
            <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-5 gap-4 items-start">
                <div>
                    <InputLabel htmlFor="business_unit_id" className="text-xs">Business Unit</InputLabel>
                    <select
                        id="business_unit_id"
                        value={data.business_unit_id}
                        onChange={(e) => setData('business_unit_id', e.target.value)}
                        className={SELECT}
                    >
                        <option value="">Select</option>
                        {businessUnits.map((unit) => (
                            <option key={unit.id} value={unit.id}>{unit.name}</option>
                        ))}
                    </select>
                    <InputError message={errors.business_unit_id} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="respondent_id" className="text-xs">Respondent</InputLabel>
                    <select
                        id="respondent_id"
                        value={data.respondent_id}
                        onChange={(e) => setData('respondent_id', e.target.value)}
                        className={SELECT}
                    >
                        <option value="">Select</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>{user.name}</option>
                        ))}
                    </select>
                    <InputError message={errors.respondent_id} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="reviewer_id" className="text-xs">Reviewer</InputLabel>
                    <select
                        id="reviewer_id"
                        value={data.reviewer_id}
                        onChange={(e) => setData('reviewer_id', e.target.value)}
                        className={SELECT}
                    >
                        <option value="">Campaign default</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>{user.name}</option>
                        ))}
                    </select>
                    <InputError message={errors.reviewer_id} className="mt-1" />
                </div>

                <div>
                    <InputLabel htmlFor="due_date" className="text-xs">Due Date</InputLabel>
                    <input
                        id="due_date"
                        type="date"
                        value={data.due_date}
                        onChange={(e) => setData('due_date', e.target.value)}
                        className={INPUT}
                    />
                    <InputError message={errors.due_date} className="mt-1" />
                </div>

                <button type="submit" disabled={processing} className="btn-primary text-sm mt-6 disabled:opacity-50">
                    Add
                </button>
            </form>
        </div>
    );
}

export default function Show({ campaign, assignments = [], businessUnits = [], users = [], can = {} }) {
    const [confirming, setConfirming] = useState(null);
    const [reviewing, setReviewing] = useState(null);

    const lifecycle = () => {
        if (confirming === 'launch') {
            router.post(route('risk.campaigns.launch', campaign.id), {}, { preserveScroll: true });
        } else if (confirming === 'close') {
            router.post(route('risk.campaigns.close', campaign.id), {}, { preserveScroll: true });
        }
        setConfirming(null);
    };

    const canAddAssignments = can.manage && ['draft', 'active'].includes(campaign.status);

    return (
        <AuthenticatedLayout title={`Campaign: ${campaign.code}`}>
            <Head title={`Campaign: ${campaign.code}`} />

            <PageHeader
                title={campaign.title}
                subtitle={`${campaign.code} · ${titleCase(campaign.type)} · ${campaign.startDate} – ${campaign.endDate}`}
                breadcrumbs={[{ label: 'Campaigns', href: route('risk.campaigns.index') }, { label: campaign.code }]}
                actions={
                    can.manage && (
                        <>
                            {campaign.status === 'draft' && (
                                <button
                                    type="button"
                                    onClick={() => setConfirming('launch')}
                                    className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700"
                                >
                                    Launch Campaign
                                </button>
                            )}
                            {['active', 'in_progress'].includes(campaign.status) && (
                                <button
                                    type="button"
                                    onClick={() => setConfirming('close')}
                                    className="btn-secondary text-sm"
                                >
                                    Close Campaign
                                </button>
                            )}
                        </>
                    )
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium text-gray-700">Campaign Progress</span>
                    <span className="text-sm font-bold text-[#1A365D]">{Math.round(campaign.progress.completed_pct)}%</span>
                </div>
                <ProgressBar progress={campaign.progress} />
                <div className="flex flex-wrap items-center gap-x-5 gap-y-1 mt-2 text-xs text-gray-500">
                    <span className="flex items-center gap-1.5">
                        <span className="w-2 h-2 rounded-full bg-green-500" />
                        {campaign.progress.completed} approved
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="w-2 h-2 rounded-full bg-blue-400" />
                        {campaign.progress.awaiting_review} awaiting review
                    </span>
                    <span className="text-gray-400">
                        of {campaign.progress.total} assignment{campaign.progress.total === 1 ? '' : 's'}
                    </span>
                    {campaign.questionnaire && (
                        <span className="text-gray-400">· questionnaire: {campaign.questionnaire}</span>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-[#1A365D]">Assignments</h3>
                </div>

                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Business Unit</th>
                                <th>Respondent</th>
                                <th>Reviewer</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assignments.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-8 text-sm text-gray-400">
                                        No assignments yet. A campaign cannot be launched without one.
                                    </td>
                                </tr>
                            )}
                            {assignments.map((assignment) => (
                                <tr key={assignment.id}>
                                    <td className="text-sm">{assignment.businessUnit ?? '—'}</td>
                                    <td className="text-sm">{assignment.respondent ?? '—'}</td>
                                    <td className="text-sm">{assignment.reviewer ?? '—'}</td>
                                    <td className={`text-sm ${assignment.overdue ? 'text-red-600 font-semibold' : ''}`}>
                                        {assignment.dueDate ?? '—'}
                                    </td>
                                    <td><StatusBadge status={assignment.status} /></td>
                                    <td className="whitespace-nowrap">
                                        {/* Whatever the status, if there are lines recorded there is
                                            something to read. */}
                                        {assignment.responsesCount > 0 && (
                                            <Link href={assignment.submissionUrl} className="text-xs text-[#1A365D] hover:underline">
                                                View submission
                                            </Link>
                                        )}

                                        {can.respond && ['pending', 'in_progress', 'rejected'].includes(assignment.status) && (
                                            <Link
                                                href={assignment.respondUrl}
                                                className={`text-xs text-[#1A365D] hover:underline ${assignment.responsesCount > 0 ? 'ml-2' : ''}`}
                                            >
                                                Respond
                                            </Link>
                                        )}

                                        {can.review && assignment.status === 'submitted' && (
                                            <button
                                                type="button"
                                                onClick={() => setReviewing(reviewing === assignment.id ? null : assignment.id)}
                                                className="text-xs text-[#1A365D] hover:underline ml-2"
                                            >
                                                {reviewing === assignment.id ? 'Cancel review' : 'Review'}
                                            </button>
                                        )}

                                        {assignment.responsesCount === 0 && assignment.status !== 'submitted' && !can.respond && (
                                            <span className="text-xs text-gray-400">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {assignments
                    .filter((assignment) => reviewing === assignment.id)
                    .map((assignment) => (
                        <ReviewPanel key={assignment.id} assignment={assignment} onClose={() => setReviewing(null)} />
                    ))}
            </div>

            {canAddAssignments && <AddAssignment campaign={campaign} businessUnits={businessUnits} users={users} />}

            <ConfirmDialog
                show={confirming !== null}
                title={confirming === 'launch' ? 'Launch this campaign?' : 'Close this campaign?'}
                message={
                    confirming === 'launch'
                        ? 'Every outstanding respondent is notified that the campaign is open, with its due date. Respondents who have already submitted or been approved are not told again.'
                        : 'Closing stops further responses. Submissions already filed remain readable.'
                }
                confirmLabel={confirming === 'launch' ? 'Launch' : 'Close'}
                variant={confirming === 'launch' ? 'success' : 'warning'}
                onConfirm={lifecycle}
                onCancel={() => setConfirming(null)}
            />
        </AuthenticatedLayout>
    );
}
