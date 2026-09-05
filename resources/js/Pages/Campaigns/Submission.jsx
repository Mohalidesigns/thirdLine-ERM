import { useRef } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import RatingBadge from '@/Components/RatingBadge';
import StatusBadge from '@/Components/StatusBadge';
import { INPUT, effectivenessLabel } from './format';

/**
 * Read-back of what a respondent filed (Phase 4.5:
 * risk/campaigns/submission.blade.php).
 *
 * The 50-line @php block that normalised the stored shapes moved to
 * App\Presenters\CampaignSubmissionPresenter, where it can be tested. It now
 * handles three shapes rather than two: RCSA worksheet lines, campaign
 * respond-form lines, and — new in 4.5 — a questionnaire answer sheet.
 */
function Score({ likelihood, impact, score, rating }) {
    if (score === null || score === undefined) {
        return <span className="text-xs text-gray-400">—</span>;
    }

    return (
        <span className="inline-flex items-center gap-1">
            <span className="text-xs text-gray-500">{likelihood} × {impact} = {score}</span>
            {rating && <RatingBadge rating={String(rating).toLowerCase()} />}
        </span>
    );
}

function ReviewPanel({ assignment }) {
    const { data, setData, post, processing, errors, transform } = useForm({ reviewer_notes: '' });

    // See the note on Show.jsx's ReviewPanel: the pressed button lives in a ref,
    // because setData is asynchronous and a Reject that posts action:'approve'
    // accepts the very submission it was meant to return.
    const action = useRef('approve');

    transform((form) => ({ ...form, action: action.current }));

    const act = (intent) => (e) => {
        e.preventDefault();
        action.current = intent;
        post(assignment.reviewUrl, { preserveScroll: true });
    };

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Review</h3>
            <InputLabel htmlFor="reviewer_notes" className="text-xs">Notes (optional)</InputLabel>
            <textarea
                id="reviewer_notes"
                rows={2}
                value={data.reviewer_notes}
                onChange={(e) => setData('reviewer_notes', e.target.value)}
                className={INPUT}
                placeholder="Why you are approving or returning this submission"
            />
            <InputError message={errors.reviewer_notes} className="mt-1" />

            <div className="flex gap-2 mt-3">
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
            </div>
        </div>
    );
}

function AnswerSheet({ answerSheet }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-[#1A365D]">Questionnaire answers</h3>
                {/* The question text is stored with the answer, not resolved at
                    display time — a questionnaire edited since would otherwise
                    restate this answer against a question nobody was asked. */}
                <p className="text-xs text-gray-400 mt-0.5">As the questions were worded when this was answered</p>
            </div>

            <div className="divide-y divide-gray-100">
                {answerSheet.sections.map((section) => (
                    <div key={section.title} className="px-5 py-4">
                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">{section.title}</h4>
                        <dl className="space-y-3">
                            {section.answers.map((answer, index) => (
                                <div key={answer.questionId ?? index}>
                                    <dt className="text-sm text-gray-700">{answer.question}</dt>
                                    <dd className="text-sm font-medium text-[#1A365D] mt-0.5">
                                        {answer.label ?? String(answer.value ?? '—')}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Submission({ campaign, assignment, lines = [], answerSheet = null, canReview = false }) {
    return (
        <AuthenticatedLayout title={`Submission: ${campaign.code}`}>
            <Head title={`Submission: ${campaign.code}`} />

            <PageHeader
                title={campaign.title}
                subtitle={`${campaign.code} · ${assignment.businessUnit ?? 'Unassigned unit'} · submitted by ${assignment.respondent ?? 'Unknown'}`}
                breadcrumbs={[
                    { label: 'Campaigns', href: route('risk.campaigns.index') },
                    { label: campaign.code, href: campaign.url },
                    { label: 'Submission' },
                ]}
                actions={<Link href={campaign.url} className="btn-secondary text-sm">Back to campaign</Link>}
            />

            <div className="space-y-6">
                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <dl className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <dt className="text-xs text-gray-500">Status</dt>
                            <dd className="mt-1"><StatusBadge status={assignment.status} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Submitted</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-900">{assignment.submittedAt ?? 'Not yet submitted'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Due</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-900">{assignment.dueDate ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">Reviewer</dt>
                            <dd className="mt-1 text-sm font-medium text-gray-900">{assignment.reviewer ?? '—'}</dd>
                        </div>
                    </dl>

                    {assignment.reviewerNotes && (
                        <div className="mt-4 pt-4 border-t border-gray-100">
                            <dt className="text-xs text-gray-500">Reviewer notes</dt>
                            <dd className="mt-1 text-sm text-gray-700">{assignment.reviewerNotes}</dd>
                        </div>
                    )}
                </div>

                {answerSheet && <AnswerSheet answerSheet={answerSheet} />}

                <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Submitted lines</h3>
                        <span className="text-xs text-gray-500">{lines.length} line{lines.length === 1 ? '' : 's'}</span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Risk</th>
                                    <th>Category</th>
                                    <th>Inherent</th>
                                    <th>Residual</th>
                                    <th>Control effectiveness</th>
                                    <th>Existing controls</th>
                                    <th>Action plan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lines.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center py-8 text-gray-400 text-sm">
                                            {answerSheet
                                                ? 'No risk lines were scored — this submission is questionnaire answers only.'
                                                : 'Nothing recorded against this assignment yet.'}
                                        </td>
                                    </tr>
                                )}
                                {lines.map((line) => {
                                    const effectiveness = effectivenessLabel(line.controlEffectiveness);

                                    return (
                                        <tr key={line.id}>
                                            <td>
                                                <span className="text-sm font-medium text-gray-900">{line.title}</span>
                                                {line.reference && (
                                                    <span className="block text-[11px] text-gray-400 mt-0.5">{line.reference}</span>
                                                )}
                                            </td>
                                            <td className="text-sm text-gray-600">{line.category ?? '—'}</td>
                                            <td>
                                                <Score
                                                    likelihood={line.inherentLikelihood}
                                                    impact={line.inherentImpact}
                                                    score={line.inherentScore}
                                                    rating={line.inherentRating}
                                                />
                                            </td>
                                            <td>
                                                <Score
                                                    likelihood={line.residualLikelihood}
                                                    impact={line.residualImpact}
                                                    score={line.residualScore}
                                                    rating={line.residualRating}
                                                />
                                            </td>
                                            <td>
                                                {effectiveness
                                                    ? <span className={`badge ${effectiveness.tone}`}>{effectiveness.label}</span>
                                                    : <span className="text-xs text-gray-400">—</span>}
                                            </td>
                                            <td className="text-sm text-gray-600 max-w-[220px]">{line.existingControls ?? '—'}</td>
                                            <td className="text-sm text-gray-600 max-w-[220px]">{line.actionPlan ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* So a reviewer never has to go back to the campaign page to act
                    on what they have just read. */}
                {canReview && <ReviewPanel assignment={assignment} />}
            </div>
        </AuthenticatedLayout>
    );
}
