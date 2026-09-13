import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * Answering a questionnaire — FR-PRT-03.
 *
 * SAVE-AND-RESUME IS THE DEFAULT AND NOT A BUTTON. Every field posts on blur.
 * A tier-1 questionnaire is forty questions that need three people and a week,
 * and a form that loses work on a dropped connection gets answered in a
 * spreadsheet and emailed instead — which is the behaviour the whole module
 * exists to end.
 *
 * A PRE-FILLED ANSWER IS SHOWN AS CONFIRM-OR-CORRECT WITH ITS SOURCE, never as
 * a blank field and never as a finished one. The vendor wrote those words for
 * a different client, possibly a year ago, about a different service; the note
 * above the field says where they came from and asks whether they still apply.
 */
export default function Respond({
    assessment = {}, progress = {}, sections = [], delegations = [], colleagues = [],
    thread = [], complianceLevels = [],
}) {
    const [openSection, setOpenSection] = useState(sections[0]?.id ?? null);
    const [delegating, setDelegating] = useState(null);

    const delegationFor = (sectionId) => delegations.find((d) => d.section_id === sectionId && d.open);

    return (
        <PortalLayout title={assessment.name}>
            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="text-lg font-semibold text-gray-900">{assessment.name}</h1>
                    <p className="text-sm text-gray-600">
                        {assessment.engagement}
                        {assessment.due_at && <> · due {assessment.due_at}</>}
                        {' · '}{assessment.status_label}
                    </p>
                </div>

                {assessment.editable && (
                    <button
                        type="button"
                        className="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                        onClick={() => router.post(route('tprm-portal.assessments.submit', assessment.uuid))}
                    >
                        Submit
                    </button>
                )}
            </div>

            {!assessment.editable && (
                <div className="mb-6 rounded border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                    This is with the reviewer and cannot be edited. If something needs correcting, say so in the
                    message thread and they can send it back.
                </div>
            )}

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-4">
                <div className="h-2 w-full overflow-hidden rounded bg-gray-100">
                    <div className="h-full bg-blue-600" style={{ width: `${progress.pct ?? 0}%` }} />
                </div>
                <p className="mt-2 text-sm text-gray-600">
                    {progress.answered} of {progress.total} answered.{' '}
                    {progress.required_answered < progress.required_total ? (
                        <span className="font-medium text-amber-700">
                            {progress.required_total - progress.required_answered} required question
                            {progress.required_total - progress.required_answered === 1 ? '' : 's'} still to go
                            before you can submit.
                        </span>
                    ) : (
                        <span className="text-green-700">Every required question is answered.</span>
                    )}
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-3 lg:col-span-2">
                    {sections.map((section) => {
                        const delegated = delegationFor(section.id);
                        const answered = section.questions.filter((q) => q.compliance && q.compliance !== 'unanswered').length;

                        return (
                            <div key={section.id} className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                                <button
                                    type="button"
                                    onClick={() => setOpenSection(openSection === section.id ? null : section.id)}
                                    className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-gray-50"
                                >
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium text-gray-900">{section.title}</span>
                                        <span className="block text-xs text-gray-500">
                                            {answered}/{section.questions.length} answered
                                            {delegated && <> · assigned to {delegated.to}</>}
                                        </span>
                                    </span>
                                    <span className="shrink-0 text-gray-400">{openSection === section.id ? '−' : '+'}</span>
                                </button>

                                {openSection === section.id && (
                                    <div className="border-t border-gray-100">
                                        {colleagues.length > 0 && assessment.editable && (
                                            <div className="border-b border-gray-100 bg-gray-50 px-4 py-2">
                                                <button
                                                    type="button"
                                                    className="text-xs font-medium text-blue-700 hover:underline"
                                                    onClick={() => setDelegating(section)}
                                                >
                                                    {delegated ? 'Reassign this section' : 'Ask a colleague to do this section'}
                                                </button>
                                            </div>
                                        )}

                                        {section.questions.map((question) => (
                                            <Question
                                                key={question.response_id}
                                                assessment={assessment}
                                                question={question}
                                                complianceLevels={complianceLevels}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                <MessageThread assessment={assessment} thread={thread} />
            </div>

            {delegating && (
                <DelegateDialog
                    assessment={assessment}
                    section={delegating}
                    colleagues={colleagues}
                    onClose={() => setDelegating(null)}
                />
            )}
        </PortalLayout>
    );
}

function Question({ assessment, question, complianceLevels }) {
    const [value, setValue] = useState(question.value ?? '');
    const [compliance, setCompliance] = useState(question.compliance ?? '');
    const [saved, setSaved] = useState(false);

    const save = (nextValue = value, nextCompliance = compliance) => {
        if (!assessment.editable) return;

        router.post(
            route('tprm-portal.assessments.answers.save', [assessment.uuid, question.response_id]),
            { value: nextValue, compliance: nextCompliance },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setSaved(true);
                    setTimeout(() => setSaved(false), 1500);
                },
            },
        );
    };

    return (
        <div className="border-b border-gray-100 px-4 py-4 last:border-b-0">
            <p className="text-sm text-gray-900">
                <span className="mr-2 font-mono text-xs text-gray-500">{question.code}</span>
                {question.text}
                {question.required && <span className="ml-1 text-red-600">*</span>}
            </p>

            {question.help_text && <p className="mt-1 text-xs text-gray-500">{question.help_text}</p>}

            {question.prefilled && question.prefill_note && (
                <p className="mt-2 rounded bg-blue-50 px-2 py-1.5 text-xs text-blue-900">{question.prefill_note}</p>
            )}

            {question.reviewer_comment && (
                <p className="mt-2 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900">
                    Reviewer: {question.reviewer_comment}
                </p>
            )}

            <div className="mt-2 flex flex-wrap gap-2">
                {complianceLevels.map((level) => (
                    <button
                        key={level.value}
                        type="button"
                        disabled={!assessment.editable}
                        onClick={() => { setCompliance(level.value); save(value, level.value); }}
                        className={`rounded border px-2 py-1 text-xs ${
                            compliance === level.value
                                ? 'border-blue-300 bg-blue-50 font-medium text-blue-800'
                                : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                        } disabled:opacity-50`}
                    >
                        {level.label}
                    </button>
                ))}
            </div>

            <textarea
                className="mt-2 w-full rounded border border-gray-200 p-2 text-sm"
                rows="3"
                disabled={!assessment.editable}
                value={value}
                onChange={(event) => setValue(event.target.value)}
                onBlur={() => save()}
                placeholder="Your answer"
            />

            <p className="mt-1 h-4 text-xs text-green-700">{saved ? 'Saved' : ''}</p>
        </div>
    );
}

/** FR-PRT-09 — on the record, not in an inbox. */
function MessageThread({ assessment, thread }) {
    const form = useForm({ body: '' });

    return (
        <aside className="rounded-lg border border-gray-200 bg-white">
            <h2 className="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">Messages</h2>

            <div className="max-h-96 space-y-3 overflow-y-auto p-4">
                {thread.length === 0 && (
                    <p className="text-sm text-gray-500">
                        Nothing yet. Ask here rather than by email — the answer stays with the assessment, so the
                        next person to look at it sees the question too.
                    </p>
                )}

                {thread.map((message) => (
                    <div
                        key={message.id}
                        className={`rounded p-2 text-sm ${
                            message.from_vendor ? 'bg-blue-50 text-blue-900' : 'bg-gray-100 text-gray-800'
                        }`}
                    >
                        <p className="whitespace-pre-wrap">{message.body}</p>
                        <p className="mt-1 text-xs opacity-70">
                            {message.from_vendor ? 'You' : 'Reviewer'} · {message.at}
                        </p>
                    </div>
                ))}
            </div>

            <form
                className="border-t border-gray-100 p-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm-portal.assessments.messages.store', assessment.uuid), {
                        preserveScroll: true,
                        onSuccess: () => form.reset('body'),
                    });
                }}
            >
                <textarea
                    className="w-full rounded border border-gray-200 p-2 text-sm"
                    rows="2"
                    value={form.data.body}
                    onChange={(event) => form.setData('body', event.target.value)}
                    placeholder="Ask your reviewer a question"
                />
                <button
                    type="submit"
                    disabled={form.processing || form.data.body.trim() === ''}
                    className="mt-2 w-full rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-40"
                >
                    Send
                </button>
            </form>
        </aside>
    );
}

function DelegateDialog({ assessment, section, colleagues, onClose }) {
    const form = useForm({ portal_user_id: colleagues[0]?.id ?? '', note: '' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form
                className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm-portal.assessments.delegate', [assessment.uuid, section.id]), {
                        onSuccess: onClose,
                    });
                }}
            >
                <h2 className="text-base font-semibold text-gray-900">Assign “{section.title}”</h2>
                <p className="mt-1 text-xs text-gray-600">
                    They will see this assessment on their own sign-in. You stay responsible for submitting it.
                </p>

                <select
                    className="mt-3 w-full rounded border border-gray-200 p-2 text-sm"
                    value={form.data.portal_user_id}
                    onChange={(event) => form.setData('portal_user_id', event.target.value)}
                >
                    {colleagues.map((colleague) => (
                        <option key={colleague.id} value={colleague.id}>
                            {colleague.name} ({colleague.email})
                        </option>
                    ))}
                </select>

                <textarea
                    className="mt-3 w-full rounded border border-gray-200 p-2 text-sm"
                    rows="2"
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                    placeholder="Anything they should know (optional)"
                />

                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button type="submit" className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white">Assign</button>
                </div>
            </form>
        </div>
    );
}
