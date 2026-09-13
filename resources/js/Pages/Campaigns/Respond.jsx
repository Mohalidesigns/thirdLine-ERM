import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import QuestionnaireSections from './QuestionnaireSections';
import { EFFECTIVENESS_OPTIONS, IMPACT_LABELS, INPUT, LIKELIHOOD_LABELS, SELECT } from './format';

/**
 * Respond to an assignment (Phase 4.5: risk/campaigns/respond.blade.php).
 *
 * TWO HALVES, and the first of them is new. If the campaign carries a
 * questionnaire, its sections are rendered above the risk lines — the Blade
 * page never rendered one even though the controller had always loaded it, so
 * a published questionnaire attached to a campaign reached no respondent.
 *
 * The second half is the register-risk assessment the Blade page did have, with
 * the control-effectiveness list corrected: it used to offer `not_applicable`,
 * a value nothing else in this product uses and the read-back screen has no
 * label for, so choosing it stored an answer that displayed as an em dash.
 */
const EMPTY_LINE = { risk_id: '', likelihood_score: '', impact_score: '', control_effectiveness: '', comments: '' };

function ScoreSelect({ id, label, labels, value, onChange, error }) {
    return (
        <div>
            <InputLabel htmlFor={id} className="text-xs">{label}</InputLabel>
            <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className={SELECT}>
                <option value="">Select</option>
                {[1, 2, 3, 4, 5].map((score) => (
                    <option key={score} value={score}>{labels[score] ? `${score} - ${labels[score]}` : score}</option>
                ))}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function RiskLine({ index, risk, line, errors, onChange }) {
    const field = (key) => `responses.${index}.${key}`;

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6">
            {risk ? (
                <>
                    <h3 className="text-sm font-semibold text-[#1A365D]">{risk.code} — {risk.title}</h3>
                    {risk.description && (
                        <p className="text-xs text-gray-500 mt-1 mb-4 line-clamp-3">{risk.description}</p>
                    )}
                </>
            ) : (
                <h3 className="text-sm font-semibold text-[#1A365D] mb-4">General assessment</h3>
            )}

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <ScoreSelect
                    id={`likelihood-${index}`}
                    label="Likelihood (1-5)"
                    labels={LIKELIHOOD_LABELS}
                    value={line.likelihood_score}
                    onChange={(value) => onChange(index, 'likelihood_score', value)}
                    error={errors[field('likelihood_score')]}
                />
                <ScoreSelect
                    id={`impact-${index}`}
                    label="Impact (1-5)"
                    labels={IMPACT_LABELS}
                    value={line.impact_score}
                    onChange={(value) => onChange(index, 'impact_score', value)}
                    error={errors[field('impact_score')]}
                />
                <div>
                    <InputLabel htmlFor={`effectiveness-${index}`} className="text-xs">Control Effectiveness</InputLabel>
                    <select
                        id={`effectiveness-${index}`}
                        value={line.control_effectiveness}
                        onChange={(e) => onChange(index, 'control_effectiveness', e.target.value)}
                        className={SELECT}
                    >
                        <option value="">Select</option>
                        {EFFECTIVENESS_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>{option.label}</option>
                        ))}
                    </select>
                    <InputError message={errors[field('control_effectiveness')]} className="mt-1" />
                </div>
            </div>

            <div className="mt-3">
                <InputLabel htmlFor={`comments-${index}`} className="text-xs">Comments / Observations</InputLabel>
                <textarea
                    id={`comments-${index}`}
                    rows={2}
                    value={line.comments}
                    onChange={(e) => onChange(index, 'comments', e.target.value)}
                    className={INPUT}
                    placeholder="Additional observations…"
                />
                <InputError message={errors[field('comments')]} className="mt-1" />
            </div>
        </div>
    );
}

export default function Respond({
    assignment,
    campaign,
    questionnaire = null,
    risks = [],
    canViewSubmission = false,
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        responses: risks.length > 0
            ? risks.map((risk) => ({ ...EMPTY_LINE, risk_id: risk.id }))
            : [{ ...EMPTY_LINE }],
        questionnaire_answers: {},
    });

    // Empty selects post as '' and the validator wants null or an integer.
    transform((form) => ({
        ...form,
        responses: form.responses.map((line) => ({
            ...line,
            risk_id: line.risk_id || null,
            likelihood_score: line.likelihood_score || null,
            impact_score: line.impact_score || null,
            control_effectiveness: line.control_effectiveness || null,
            comments: line.comments || null,
        })),
    }));

    const changeLine = (index, key, value) =>
        setData('responses', data.responses.map((line, i) => (i === index ? { ...line, [key]: value } : line)));

    const changeAnswer = (questionId, value) =>
        setData('questionnaire_answers', { ...data.questionnaire_answers, [questionId]: value });

    const submit = (e) => {
        e.preventDefault();
        post(assignment.submitUrl);
    };

    return (
        <AuthenticatedLayout title="Respond to Assessment">
            <Head title="Respond to Assessment" />

            <PageHeader
                title={campaign.title}
                subtitle={`${assignment.businessUnit ?? 'Unassigned unit'} · due ${assignment.dueDate ?? '—'}`}
                breadcrumbs={[
                    { label: 'Campaigns', href: route('risk.campaigns.index') },
                    { label: campaign.code, href: campaign.url },
                    { label: 'Respond' },
                ]}
            />

            <div className="max-w-4xl space-y-6">
                {assignment.existingLines > 0 && (
                    <div className="bg-blue-50 border border-blue-200 rounded-xl p-4 flex items-center justify-between gap-4">
                        <p className="text-sm text-blue-800">
                            {assignment.existingLines} line{assignment.existingLines === 1 ? '' : 's'} already recorded
                            against this assignment. Submitting below replaces them.
                        </p>
                        {canViewSubmission && (
                            <Link href={assignment.submissionUrl} className="text-sm font-medium text-blue-700 hover:underline whitespace-nowrap">
                                View submission
                            </Link>
                        )}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-6">
                    <QuestionnaireSections
                        questionnaire={questionnaire}
                        answers={data.questionnaire_answers}
                        errors={errors}
                        onChange={changeAnswer}
                    />

                    {risks.length === 0 && (
                        <div className="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-800">
                            No register risks are recorded for this business unit, so there is nothing to score line by
                            line. Record a general assessment below{questionnaire ? ' alongside your answers above' : ''}.
                        </div>
                    )}

                    {data.responses.map((line, index) => (
                        <RiskLine
                            key={index}
                            index={index}
                            risk={risks[index] ?? null}
                            line={line}
                            errors={errors}
                            onChange={changeLine}
                        />
                    ))}

                    <InputError message={errors.responses} />

                    <div className="flex items-center justify-end gap-3">
                        <Link href={campaign.url} className="btn-secondary text-sm">Cancel</Link>
                        <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                            <span className="material-symbols-outlined text-lg">send</span> Submit Assessment
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
