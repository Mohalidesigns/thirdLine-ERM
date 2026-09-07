import { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import TierBadge from '@/Components/Tprm/TierBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Assessment Review screen — TRD §11's split view, and where the module's
 * argument becomes visible to a reviewer.
 *
 * THREE THINGS ON EVERY ANSWER, deliberately together:
 *
 *   The ASSURANCE CHIP, with its definition on hover. That chip is the
 *   difference between 0.35 and 0.85, and a reviewer who does not know what
 *   `independently_assured` means will accept a policy PDF as one.
 *
 *   The COMPUTED CONFIDENCE the answer earned, so the arithmetic is not
 *   hidden behind a single assessment-level number.
 *
 *   Whether the answer was AUTO-ANSWERED, and from where. A carried answer
 *   that looks identical to a fresh one is how a reviewer accepts last year's
 *   claim believing the vendor just made it.
 */
const ASSURANCE_TONE = {
    self_attested: 'bg-red-50 text-red-800 border-red-200',
    documented: 'bg-amber-50 text-amber-800 border-amber-200',
    independently_assured: 'bg-blue-50 text-blue-800 border-blue-200',
    validated: 'bg-green-50 text-green-800 border-green-200',
};

const COMPLIANCE_TONE = {
    compliant: 'text-green-700',
    partial: 'text-amber-700',
    non_compliant: 'text-red-700',
    na: 'text-gray-500',
    unanswered: 'text-gray-400',
};

export default function Review({ assessment, sections = [], liveScore = {}, scoping = {}, messages = [], options = {}, can = {} }) {
    const [openSection, setOpenSection] = useState(sections[0]?.code ?? null);
    const [reviewing, setReviewing] = useState(null);
    const [showScoping, setShowScoping] = useState(false);

    const pending = useMemo(
        () => sections.flatMap((s) => s.responses).filter((r) => r.reviewer_status === 'pending').length,
        [sections],
    );

    const messagesByResponse = useMemo(() => {
        const map = {};
        messages.forEach((m) => {
            if (m.response_id) (map[m.response_id] ??= []).push(m);
        });
        return map;
    }, [messages]);

    return (
        <AppLayout title="Assessment review">
            <Head title={`${assessment.engagement.reference} — assessment`} />

            <PageHeader
                title={assessment.template.name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <a href={assessment.engagement.url} className="font-mono text-xs text-blue-700 hover:underline">
                            {assessment.engagement.reference}
                        </a>
                        <span className="text-gray-300">·</span>
                        <span>{assessment.engagement.third_party}</span>
                        <span className="text-gray-300">·</span>
                        <span className="text-xs">v{assessment.template.version}</span>
                    </span>
                }
                actions={
                    <div className="flex items-center gap-2">
                        {assessment.engagement.tier && <TierBadge tier={assessment.engagement.tier} />}
                        <StatusBadge status={assessment.status} />
                    </div>
                }
            />

            {assessment.template.catalogue_status === 'partial' && (
                <div className="card mb-4 border-l-4 border-amber-400 p-4">
                    <p className="text-xs text-amber-900">
                        <span className="font-semibold">This pack is a subset.</span>{' '}
                        {assessment.template.catalogue_note}
                    </p>
                </div>
            )}

            {assessment.days_overdue > 0 && (
                <div className="card mb-4 border-l-4 border-red-500 p-4 text-sm text-red-800">
                    Overdue by {assessment.days_overdue} days — due {assessment.due_at}.
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {sections.map((section) => (
                        <div key={section.code} className="card overflow-hidden">
                            <button
                                type="button"
                                onClick={() => setOpenSection(openSection === section.code ? null : section.code)}
                                className="flex w-full items-center justify-between px-5 py-3 text-left hover:bg-gray-50"
                            >
                                <span>
                                    <span className="text-sm font-semibold text-gray-900">{section.title}</span>
                                    {section.domain && (
                                        <span className="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">
                                            {section.domain}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-3 text-xs text-gray-500">
                                    <span>{section.responses.length} questions</span>
                                    {liveScore.sections?.[section.code] && (
                                        <span className="tabular-nums">
                                            AC {liveScore.sections[section.code].ac}
                                        </span>
                                    )}
                                    <span className="material-symbols-outlined text-lg">
                                        {openSection === section.code ? 'expand_less' : 'expand_more'}
                                    </span>
                                </span>
                            </button>

                            {openSection === section.code && (
                                <div className="divide-y divide-gray-100 border-t border-gray-100">
                                    {section.responses.map((response) => (
                                        <Answer
                                            key={response.id}
                                            assessment={assessment}
                                            response={response}
                                            messages={messagesByResponse[response.id] ?? []}
                                            options={options}
                                            canReview={can.review}
                                            open={reviewing === response.id}
                                            onToggle={() => setReviewing(reviewing === response.id ? null : response.id)}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                <div className="lg:col-span-1">
                    <div className="sticky top-6 space-y-4">
                        <div className="card p-5">
                            <h3 className="text-sm font-semibold text-gray-900">Live score</h3>
                            <p className="mt-0.5 text-xs text-gray-500">
                                Computed from the answers as they stand, with the same scorer that fixes the
                                final number.
                            </p>

                            <dl className="mt-4 space-y-3">
                                <Metric label="Assurance coverage (AC)" value={liveScore.ac} />
                                <Metric
                                    label="Evidence confidence (EC)"
                                    value={liveScore.ec}
                                    absent="Nothing evidenced yet"
                                />
                                <Metric label="Mitigation (M)" value={liveScore.m} absent="Not measurable" />
                            </dl>

                            {liveScore.coverage_capped && (
                                <div className="mt-4 rounded bg-red-50 p-2.5 text-xs text-red-900">
                                    <p className="font-semibold">Coverage capped at 0.5</p>
                                    <p className="mt-1">
                                        A critical question is answered non-compliant, which caps the whole
                                        assessment regardless of the rest:{' '}
                                        {liveScore.critical_failures?.join(', ')}.
                                    </p>
                                </div>
                            )}

                            <p className="mt-4 text-xs text-gray-500">
                                {liveScore.scored_count} of {liveScore.applicable_count} answers scored.
                                {pending > 0 && ` ${pending} not yet reviewed.`}
                            </p>

                            {can.validate && (
                                <button
                                    type="button"
                                    disabled={pending > 0}
                                    title={pending > 0 ? 'Review every answer first.' : undefined}
                                    onClick={() => router.post(tryRoute('tprm.assessments.validate', assessment.id), {}, { preserveScroll: true })}
                                    className="btn-primary mt-4 w-full text-sm disabled:opacity-50"
                                >
                                    Validate and score
                                </button>
                            )}

                            {can.review && (
                                <button
                                    type="button"
                                    onClick={() => router.post(tryRoute('tprm.assessments.clarify', assessment.id), {}, { preserveScroll: true })}
                                    className="btn-secondary mt-2 w-full text-sm"
                                >
                                    Return flagged answers to the vendor
                                </button>
                            )}
                        </div>

                        {/* FR-ASM-03's supervisory defence: why this is a
                            forty-question assessment and not a two-hundred one. */}
                        <div className="card p-5">
                            <button
                                type="button"
                                onClick={() => setShowScoping((v) => !v)}
                                className="flex w-full items-center justify-between text-left"
                                aria-expanded={showScoping}
                            >
                                <span className="text-sm font-semibold text-gray-900">Why these questions</span>
                                <span className="material-symbols-outlined text-lg">
                                    {showScoping ? 'expand_less' : 'expand_more'}
                                </span>
                            </button>
                            <p className="mt-1 text-xs text-gray-500">
                                {scoping.included_count} of {scoping.total_count} questions in the pack apply to
                                this engagement.
                            </p>

                            {showScoping && (
                                <div className="mt-3 space-y-3">
                                    {(scoping.exclusions ?? []).map((group, i) => (
                                        <div key={i}>
                                            <p className="text-xs text-gray-700">{group.reason}</p>
                                            <p className="mt-1 font-mono text-[11px] text-gray-500">
                                                {group.questions.join(', ')}
                                            </p>
                                        </div>
                                    ))}
                                    {(scoping.unresolved_facts ?? []).length > 0 && (
                                        <div className="rounded bg-amber-50 p-2.5">
                                            <p className="text-xs font-medium text-amber-900">
                                                Asked because the rule could not be evaluated
                                            </p>
                                            <ul className="mt-1 list-inside list-disc text-[11px] text-amber-800">
                                                {scoping.unresolved_facts.map((f, i) => <li key={i}>{f}</li>)}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function Answer({ assessment, response, messages, options, canReview, open, onToggle }) {
    const { data, setData, post, processing, errors } = useForm({
        reviewer_status: response.reviewer_status === 'pending' ? 'accepted' : response.reviewer_status,
        reviewer_comment: response.reviewer_comment ?? '',
        compliance: response.compliance,
        assurance_level: response.assurance_level ?? '',
        message: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(tryRoute('tprm.assessments.review', [assessment.id, response.id]), {
            preserveScroll: true,
            onSuccess: onToggle,
        });
    };

    return (
        <div className={`px-5 py-4 ${response.is_auto_answered ? 'border-l-2 border-blue-300 bg-blue-50/30' : ''}`}>
            <div className="flex items-start justify-between gap-4">
                <div className="flex-1">
                    <p className="text-sm text-gray-900">
                        <span className="mr-2 font-mono text-xs text-gray-400">{response.question_code}</span>
                        {response.question}
                        {response.is_critical && (
                            <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-medium text-red-800">
                                Critical
                            </span>
                        )}
                    </p>

                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        {(response.controls ?? []).map((c, i) => (
                            <span key={i} className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[10px] text-gray-600">
                                {c.framework} {c.control_id}
                            </span>
                        ))}
                        <span className="text-gray-400">weight {response.risk_weight}</span>
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <span className={`text-sm font-medium ${COMPLIANCE_TONE[response.compliance] ?? 'text-gray-600'}`}>
                            {response.compliance_label}
                        </span>

                        {response.assurance_level && (
                            <span
                                title={options.assurance?.find((a) => a.value === response.assurance_level)?.definition}
                                className={`rounded border px-1.5 py-0.5 text-[11px] font-medium ${ASSURANCE_TONE[response.assurance_level] ?? ''}`}
                            >
                                {response.assurance_label}
                            </span>
                        )}

                        {response.computed_conf !== null && response.computed_conf !== undefined && (
                            <span className="text-[11px] tabular-nums text-gray-500">
                                conf {response.computed_conf}
                            </span>
                        )}

                        {response.is_auto_answered && (
                            <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-medium text-blue-800">
                                Auto-answered
                            </span>
                        )}
                    </div>

                    {/* The source, cited. A carried answer that looks like a
                        fresh one is how a reviewer accepts last year's claim
                        believing the vendor just made it. */}
                    {response.is_auto_answered && response.auto_answer_source && (
                        <p className="mt-1 text-[11px] italic text-blue-800">
                            {response.auto_answer_source.citation}
                            {response.carry_forward_cycles > 0 &&
                                ` Carried ${response.carry_forward_cycles} cycle(s) — its confidence is decayed accordingly.`}
                        </p>
                    )}

                    {response.vendor_comment && (
                        <p className="mt-2 rounded bg-gray-50 p-2 text-xs text-gray-700">{response.vendor_comment}</p>
                    )}

                    {messages.map((m) => (
                        <p key={m.id} className="mt-2 border-l-2 border-gray-200 pl-2 text-xs text-gray-600">
                            <span className="font-medium">{m.author_type === 'vendor' ? 'Vendor' : 'Reviewer'}</span>{' '}
                            <span className="text-gray-400">{m.at}</span>
                            <br />
                            {m.body}
                        </p>
                    ))}
                </div>

                <div className="flex shrink-0 flex-col items-end gap-1">
                    <ReviewChip status={response.reviewer_status} />
                    {canReview && (
                        <button type="button" onClick={onToggle} className="text-xs font-medium text-blue-700 hover:underline">
                            {open ? 'Close' : 'Review'}
                        </button>
                    )}
                </div>
            </div>

            {open && canReview && (
                <form onSubmit={submit} className="mt-4 space-y-3 rounded-md bg-gray-50 p-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label className="block text-xs font-medium text-gray-700">Verdict</label>
                            <select value={data.reviewer_status} onChange={(e) => setData('reviewer_status', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="accepted">Accept</option>
                                <option value="rejected">Reject</option>
                                <option value="clarification_requested">Ask for clarification</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700">Compliance</label>
                            <select value={data.compliance} onChange={(e) => setData('compliance', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                {(options.compliance ?? []).map((o) => (
                                    <option key={o.value} value={o.value}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700">Assurance</label>
                            <select value={data.assurance_level} onChange={(e) => setData('assurance_level', e.target.value)}
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="">Not stated</option>
                                {(options.assurance ?? []).map((o) => (
                                    <option key={o.value} value={o.value} title={o.definition}>{o.label}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-gray-700">
                            Reviewer note
                            {['rejected', 'clarification_requested'].includes(data.reviewer_status) && (
                                <span className="ml-0.5 text-red-600">*</span>
                            )}
                        </label>
                        <textarea rows="2" value={data.reviewer_comment} onChange={(e) => setData('reviewer_comment', e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" />
                        {errors.reviewer_comment && <p className="mt-1 text-xs text-red-600">{errors.reviewer_comment}</p>}
                    </div>

                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={onToggle} className="btn-secondary text-sm">Cancel</button>
                        <button type="submit" disabled={processing} className="btn-primary text-sm">Save review</button>
                    </div>
                </form>
            )}
        </div>
    );
}

function ReviewChip({ status }) {
    const map = {
        pending: ['Not reviewed', 'bg-gray-100 text-gray-600'],
        accepted: ['Accepted', 'bg-green-100 text-green-800'],
        rejected: ['Rejected', 'bg-red-100 text-red-800'],
        clarification_requested: ['Queried', 'bg-amber-100 text-amber-800'],
    };
    const [label, classes] = map[status] ?? [status, 'bg-gray-100 text-gray-600'];

    return <span className={`whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-medium ${classes}`}>{label}</span>;
}

function Metric({ label, value, absent = '—' }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-sm text-gray-600">{label}</dt>
            <dd className="text-lg font-semibold tabular-nums text-gray-900">
                {value === null || value === undefined
                    ? <span className="text-sm font-normal text-gray-500">{absent}</span>
                    : value}
            </dd>
        </div>
    );
}
