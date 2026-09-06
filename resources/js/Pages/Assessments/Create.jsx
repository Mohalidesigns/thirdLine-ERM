import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import useAssessmentPreview from '@/hooks/useAssessmentPreview';

const INPUT =
    'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
const SELECT = `${INPUT} bg-white`;

const ucfirst = (value) => (value ? value.charAt(0).toUpperCase() + value.slice(1) : '');

function Card({ title, step, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6">
            <h2 className="text-lg font-semibold text-[#1A365D] mb-1">{title}</h2>
            {step && <p className="text-sm text-gray-500 mb-5">{step}</p>}
            {children}
        </div>
    );
}

/**
 * A figure the server derived, or an explicit absence.
 *
 * Never a zero standing in for "not yet scored": an unscored chain has no
 * inherent risk, and printing 0/25 beside a rating band is the fabrication
 * NoFabricatedNumbersTest exists to stop.
 */
function Derived({ label, value, rating, suffix, hint }) {
    return (
        <div>
            <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">{label}</p>
            {value === null || value === undefined ? (
                <p className="text-lg font-semibold text-gray-400 italic">Not yet scored</p>
            ) : (
                <p className="text-2xl font-bold text-[#1A365D] flex items-baseline gap-2">
                    {value}
                    {suffix && <span className="text-sm font-normal text-gray-500">{suffix}</span>}
                    {rating && <RatingBadge rating={rating} />}
                </p>
            )}
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
        </div>
    );
}

/** Migration Phase 3.3: risk/assessments/create.blade.php, both create and edit. */
export default function Create({
    risk,
    assessment = null,
    causes = [],
    causeCategories = [],
    causeSources = [],
    likelihoodLabels = {},
    impactLabels = {},
    dimensions = [],
    controls = [],
    effectivenessRatings = [],
    strategies = [],
    actionPlans = [],
    users = [],
    linkedKris = [],
    availableKris = [],
    previousAssessment = null,
    stages = [],
}) {
    const editing = assessment !== null;
    const [stage, setStage] = useState(0);

    const { data, setData, post, put, processing, errors, transform } = useForm({
        risk_id: risk.id,
        assessment_type: assessment?.assessment_type ?? 'periodic',
        assessment_date: assessment?.assessment_date ?? new Date().toISOString().slice(0, 10),
        causes: causes.length > 0 ? causes : [],
        likelihood: assessment?.likelihood ?? '',
        likelihood_rationale: assessment?.likelihood_rationale ?? '',
        impacts: Object.fromEntries(
            dimensions.map((dim) => [dim.code, assessment?.impacts?.[dim.code] ?? '']),
        ),
        controls: Object.fromEntries(
            controls.map((control) => [
                control.control_id,
                {
                    design_effectiveness: control.design_effectiveness ?? '',
                    operating_effectiveness: control.operating_effectiveness ?? '',
                    notes: control.notes ?? '',
                    evidence_ref: control.evidence_ref ?? '',
                },
            ]),
        ),
        override: false,
        residual_likelihood: assessment?.residual_likelihood ?? '',
        residual_impact: assessment?.residual_impact ?? '',
        residual_justification: assessment?.residual_justification ?? '',
        treatment_strategy: assessment?.treatment_strategy ?? '',
        actions: actionPlans.length > 0 ? actionPlans : [],
        kri_ids: linkedKris.map((kri) => kri.id),
        rationale: assessment?.assessment_notes ?? '',
        recommendations: '',
        action: 'draft',
    });

    // What the server needs to score the chain. The residual pair is sent only
    // while the assessor is actually overriding, so echoing back a pre-filled
    // derived value cannot brand the assessment an override.
    const chain = useMemo(
        () => ({
            likelihood: data.likelihood,
            impacts: data.impacts,
            controls: data.controls,
            ...(data.override
                ? { residual_likelihood: data.residual_likelihood, residual_impact: data.residual_impact }
                : {}),
        }),
        [data.likelihood, data.impacts, data.controls, data.override, data.residual_likelihood, data.residual_impact],
    );

    const { preview, loading } = useAssessmentPreview(risk.id, chain);

    const inherent = preview?.inherent ?? {};
    const residual = preview?.residual ?? null;
    const aggregate = preview?.aggregate ?? {};
    const controlPreview = useMemo(
        () => Object.fromEntries((preview?.controls ?? []).map((row) => [row.id, row])),
        [preview],
    );

    // The form posts the dimensions as `impact_<dimension>` columns, which is
    // the shape StoreAssessmentRequest validates and persistChain() writes.
    transform((form) => {
        const { impacts, override, ...rest } = form;

        return {
            ...rest,
            ...Object.fromEntries(Object.entries(impacts).map(([code, value]) => [`impact_${code}`, value])),
            // A residual pair is only posted when it is a judgement. Sending
            // the derived values back would make every assessment an override
            // in the eyes of applyToAssessment().
            residual_likelihood: override ? form.residual_likelihood : null,
            residual_impact: override ? form.residual_impact : null,
        };
    });

    const submit = (e, action) => {
        e.preventDefault();
        setData('action', action);

        // setData is async; post the value directly rather than reading it back.
        const send = editing ? put : post;
        const url = editing
            ? route('risk.assessments.update', assessment.id)
            : route('risk.assessments.store');

        transform((form) => {
            const { impacts, override, ...rest } = form;

            return {
                ...rest,
                action,
                ...Object.fromEntries(Object.entries(impacts).map(([code, value]) => [`impact_${code}`, value])),
                residual_likelihood: override ? form.residual_likelihood : null,
                residual_impact: override ? form.residual_impact : null,
            };
        });

        send(url);
    };

    const setControl = (controlId, field, value) =>
        setData('controls', {
            ...data.controls,
            [controlId]: { ...data.controls[controlId], [field]: value },
        });

    const setImpact = (code, value) => setData('impacts', { ...data.impacts, [code]: value });

    const addCause = () =>
        setData('causes', [
            ...data.causes,
            { id: null, description: '', cause_category_id: '', source: '', is_primary: false },
        ]);

    const setCause = (index, field, value) =>
        setData(
            'causes',
            data.causes.map((cause, i) => {
                if (i !== index) {
                    // Only one cause can be the primary one.
                    return field === 'is_primary' && value ? { ...cause, is_primary: false } : cause;
                }

                return { ...cause, [field]: value };
            }),
        );

    const addAction = () =>
        setData('actions', [
            ...data.actions,
            { action_title: '', action_description: '', owner_id: '', target_date: '', priority: 'medium' },
        ]);

    const setAction = (index, field, value) =>
        setData(
            'actions',
            data.actions.map((action, i) => (i === index ? { ...action, [field]: value } : action)),
        );

    const toggleKri = (id) =>
        setData('kri_ids', data.kri_ids.includes(id) ? data.kri_ids.filter((k) => k !== id) : [...data.kri_ids, id]);

    const axisOptions = (labels) =>
        Object.entries(labels).map(([value, label]) => (
            <option key={value} value={value}>
                {value} - {label}
            </option>
        ));

    const title = editing ? 'Edit Assessment' : 'New Assessment';

    return (
        <AuthenticatedLayout title={title}>
            <Head title={title} />

            <PageHeader
                title={title}
                subtitle={`${risk.risk_code} — ${risk.title}`}
                breadcrumbs={[
                    { label: 'Assessments', href: route('risk.assessments.index') },
                    { label: title },
                ]}
            />

            <form onSubmit={(e) => submit(e, 'draft')}>
                <nav className="bg-white rounded-xl border border-gray-200 p-2 mb-6 flex overflow-x-auto" aria-label="Assessment stages">
                    {stages.map((meta, index) => (
                        <button
                            key={meta.label}
                            type="button"
                            onClick={() => setStage(index)}
                            className={`flex-1 min-w-[9rem] flex items-center gap-2 px-3 py-2.5 rounded-lg text-left transition-colors ${
                                stage === index ? 'bg-[#F0F4F8] text-[#1A365D]' : 'text-gray-500 hover:bg-gray-50'
                            }`}
                        >
                            <span className="material-symbols-outlined text-lg">{meta.icon}</span>
                            <span className="min-w-0">
                                <span className="block text-sm font-medium truncate">{meta.label}</span>
                                <span className="block text-[11px] text-gray-400">Step {meta.steps}</span>
                            </span>
                        </button>
                    ))}
                </nav>

                {/* ---- Stage 1 — Steps 1-2: the risk and its root causes ---- */}
                {stage === 0 && (
                    <div className="space-y-6">
                        <Card title="Assessment context" step="Step 1 — the risk under assessment.">
                            <div className="bg-gray-50 rounded-lg p-4 mb-5">
                                <p className="text-sm font-semibold text-[#1A365D]">
                                    {risk.risk_code} — {risk.title}
                                </p>
                                {risk.category && <p className="text-xs text-gray-500 mt-1">{risk.category}</p>}
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Assessment type <span className="text-red-500">*</span>
                                    </label>
                                    <select
                                        value={data.assessment_type}
                                        onChange={(e) => setData('assessment_type', e.target.value)}
                                        className={SELECT}
                                    >
                                        {['initial', 'periodic', 'event_driven', 'triggered', 'annual', 'full', 'targeted'].map((value) => (
                                            <option key={value} value={value}>
                                                {ucfirst(value.replace('_', ' '))}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.assessment_type} className="mt-1" />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-700 mb-2">
                                        Assessment date <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="date"
                                        value={data.assessment_date}
                                        onChange={(e) => setData('assessment_date', e.target.value)}
                                        className={INPUT}
                                    />
                                    <InputError message={errors.assessment_date} className="mt-1" />
                                </div>
                            </div>
                        </Card>

                        <Card
                            title="Root causes"
                            step="Step 2 — what actually drives this risk. Causes stay on the risk between assessments and are never deleted here."
                        >
                            <div className="space-y-4">
                                {data.causes.map((cause, index) => (
                                    <div key={index} className="border border-gray-200 rounded-lg p-4 space-y-3">
                                        <textarea
                                            rows={2}
                                            value={cause.description}
                                            onChange={(e) => setCause(index, 'description', e.target.value)}
                                            placeholder="What drives this risk?"
                                            className={INPUT}
                                        />
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <select
                                                value={cause.cause_category_id ?? ''}
                                                onChange={(e) => setCause(index, 'cause_category_id', e.target.value)}
                                                className={SELECT}
                                            >
                                                <option value="">Cause category…</option>
                                                {causeCategories.map((category) => (
                                                    <option key={category.id} value={category.id}>
                                                        {category.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <select
                                                value={cause.source ?? ''}
                                                onChange={(e) => setCause(index, 'source', e.target.value)}
                                                className={SELECT}
                                            >
                                                <option value="">How was it identified?</option>
                                                {causeSources.map((source) => (
                                                    <option key={source.value} value={source.value}>
                                                        {source.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(cause.is_primary)}
                                                    onChange={(e) => setCause(index, 'is_primary', e.target.checked)}
                                                    className="rounded border-gray-300"
                                                />
                                                Primary cause
                                            </label>
                                        </div>
                                        <InputError message={errors[`causes.${index}.description`]} />
                                    </div>
                                ))}
                            </div>

                            <button type="button" onClick={addCause} className="btn-secondary text-sm mt-4 inline-flex items-center gap-1">
                                <span className="material-symbols-outlined text-sm">add</span> Add cause
                            </button>
                        </Card>
                    </div>
                )}

                {/* ---- Stage 2 — Steps 3-5: likelihood, impact, inherent ---- */}
                {stage === 1 && (
                    <div className="space-y-6">
                        <Card title="Likelihood" step="Step 3 — how likely the risk is to materialise, before controls are considered.">
                            <div className="max-w-md">
                                <select
                                    value={data.likelihood}
                                    onChange={(e) => setData('likelihood', e.target.value)}
                                    className={SELECT}
                                >
                                    <option value="">Select likelihood</option>
                                    {axisOptions(likelihoodLabels)}
                                </select>
                                <InputError message={errors.likelihood} className="mt-1" />
                            </div>
                            <textarea
                                rows={2}
                                value={data.likelihood_rationale}
                                onChange={(e) => setData('likelihood_rationale', e.target.value)}
                                placeholder="Why that likelihood?"
                                className={`${INPUT} mt-4`}
                            />
                        </Card>

                        <Card title="Impact" step="Step 4 — how bad it would be across each dimension your organisation scores.">
                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                {dimensions.map((dim) => (
                                    <div key={dim.code}>
                                        <label className="block text-xs font-semibold text-gray-600 mb-1.5">{dim.label}</label>
                                        <select
                                            value={data.impacts[dim.code] ?? ''}
                                            onChange={(e) => setImpact(dim.code, e.target.value)}
                                            className={SELECT}
                                        >
                                            <option value="">Not scored</option>
                                            {axisOptions(impactLabels)}
                                        </select>
                                        {dim.hint && <p className="text-xs text-gray-500 mt-1">{dim.hint}</p>}
                                        <InputError message={errors[`impact_${dim.code}`]} className="mt-1" />
                                    </div>
                                ))}
                            </div>
                            <InputError message={errors.impact_financial} className="mt-2" />
                        </Card>

                        <Card title="Inherent risk" step="Step 5 — calculated, not entered: likelihood × aggregated impact.">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 bg-gray-50 rounded-lg p-5">
                                <Derived label="Aggregated impact" value={preview?.impactScore || null} />
                                <Derived
                                    label="Inherent score"
                                    value={inherent.score ?? null}
                                    rating={inherent.rating}
                                    suffix={inherent.score ? `(L${inherent.likelihood} × I${inherent.impact})` : null}
                                />
                                <div className="text-xs text-gray-500 self-end">
                                    {loading ? 'Scoring…' : 'Scored by the server, using your organisation’s scoring profile.'}
                                </div>
                            </div>
                        </Card>
                    </div>
                )}

                {/* ---- Stage 3 — Steps 6-7: controls and effectiveness ---- */}
                {stage === 2 && (
                    <Card
                        title="Existing controls"
                        step="Steps 6-7 — the controls this risk already has, rated as they stand on the assessment date."
                    >
                        {controls.length === 0 ? (
                            <p className="text-sm text-gray-500">
                                No controls are mapped to this risk, so residual risk cannot be derived from them. Map a
                                control on the risk, or record an override on the next stage with a justification.
                            </p>
                        ) : (
                            <>
                                <div className="grid grid-cols-1 md:grid-cols-3 gap-6 bg-gray-50 rounded-lg p-5 mb-5">
                                    <Derived
                                        label="Overall effectiveness"
                                        value={aggregate.overall ?? null}
                                        suffix="%"
                                        hint={`${aggregate.rated ?? 0} of ${controls.length} rated`}
                                    />
                                    <Derived label="Assurance on likelihood" value={aggregate.likelihood ?? null} suffix="%" />
                                    <Derived label="Assurance on impact" value={aggregate.impact ?? null} suffix="%" />
                                </div>

                                {(aggregate.unrated_key_controls ?? 0) > 0 && (
                                    <div className="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
                                        {aggregate.unrated_key_controls} key control
                                        {aggregate.unrated_key_controls === 1 ? ' is' : 's are'} unrated. A key control
                                        nobody rated is the one gap that should stop this assessment being submitted with
                                        a straight face.
                                    </div>
                                )}

                                <div className="space-y-3">
                                    {controls.map((control) => {
                                        const scored = controlPreview[control.control_id] ?? {};

                                        return (
                                            <div key={control.control_id} className="border border-gray-200 rounded-lg p-4">
                                                <div className="flex items-start justify-between gap-3 mb-3">
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-semibold text-[#1A365D]">
                                                            {control.control_code} — {control.control_name}
                                                            {control.is_key_control && (
                                                                <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">
                                                                    Key
                                                                </span>
                                                            )}
                                                        </p>
                                                        <p className="text-xs text-gray-500">
                                                            {ucfirst(control.control_type) || 'Untyped'} · reduces{' '}
                                                            {control.axis} · weight {control.control_weight}
                                                        </p>
                                                    </div>
                                                    <div className="text-right flex-shrink-0">
                                                        {scored.effective === null || scored.effective === undefined ? (
                                                            <span className="text-xs text-gray-400">Not rated</span>
                                                        ) : (
                                                            <span className="text-sm font-semibold text-[#1A365D]">
                                                                {scored.effective}%
                                                            </span>
                                                        )}
                                                        {scored.changed && (
                                                            <p className="text-[11px] text-blue-600">Changed since last</p>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                    {['design_effectiveness', 'operating_effectiveness'].map((field) => (
                                                        <div key={field}>
                                                            <label className="block text-xs font-semibold text-gray-600 mb-1">
                                                                {field === 'design_effectiveness' ? 'Design' : 'Operating'}
                                                            </label>
                                                            <select
                                                                value={data.controls[control.control_id]?.[field] ?? ''}
                                                                onChange={(e) => setControl(control.control_id, field, e.target.value)}
                                                                className={SELECT}
                                                            >
                                                                <option value="">Not rated</option>
                                                                {effectivenessRatings.map((rating) => (
                                                                    <option key={rating.value} value={rating.value}>
                                                                        {rating.label}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                    ))}
                                                </div>

                                                {scored.finding && (
                                                    <p className="text-[11px] text-amber-600 mt-2">{scored.finding}</p>
                                                )}

                                                <input
                                                    type="text"
                                                    value={data.controls[control.control_id]?.evidence_ref ?? ''}
                                                    onChange={(e) => setControl(control.control_id, 'evidence_ref', e.target.value)}
                                                    placeholder="Evidence reference"
                                                    className={`${INPUT} mt-3`}
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </Card>
                )}

                {/* ---- Stage 4 — Step 8: residual risk ---- */}
                {stage === 3 && (
                    <Card
                        title="Residual risk"
                        step="Step 8 — what remains after the controls above. Derived from inherent risk and control effectiveness."
                    >
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 bg-gray-50 rounded-lg p-5 mb-5">
                            <Derived
                                label="Derived residual"
                                value={preview?.derived?.score ?? null}
                                rating={preview?.derived?.rating}
                                suffix={
                                    preview?.derived
                                        ? `(L${preview.derived.likelihood} × I${preview.derived.impact})`
                                        : null
                                }
                                hint={
                                    preview?.derived
                                        ? `Formula target ${preview.target}, rounded to the nearest cell on the matrix.`
                                        : 'Rate at least one control to derive a residual score.'
                                }
                            />
                            <Derived
                                label="Recorded residual"
                                value={residual?.score ?? null}
                                rating={residual?.rating}
                                hint={preview?.isOverride ? 'Overridden by you.' : 'As derived.'}
                            />
                            <div className="text-xs text-gray-500 self-end">
                                Scored by the server, so a residual formula your organisation configured is the one
                                applied here — not an approximation.
                            </div>
                        </div>

                        <label className="flex items-center gap-2 text-sm text-gray-700 mb-4">
                            <input
                                type="checkbox"
                                checked={data.override}
                                onChange={(e) => setData('override', e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            Override the derived residual with my own judgement
                        </label>

                        {data.override && (
                            <div className="space-y-4 border border-amber-200 bg-amber-50/50 rounded-lg p-4">
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-600 mb-1">Residual likelihood</label>
                                        <select
                                            value={data.residual_likelihood}
                                            onChange={(e) => setData('residual_likelihood', e.target.value)}
                                            className={SELECT}
                                        >
                                            <option value="">Select</option>
                                            {axisOptions(likelihoodLabels)}
                                        </select>
                                        <InputError message={errors.residual_likelihood} className="mt-1" />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-gray-600 mb-1">Residual impact</label>
                                        <select
                                            value={data.residual_impact}
                                            onChange={(e) => setData('residual_impact', e.target.value)}
                                            className={SELECT}
                                        >
                                            <option value="">Select</option>
                                            {axisOptions(impactLabels)}
                                        </select>
                                        <InputError message={errors.residual_impact} className="mt-1" />
                                    </div>
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-gray-600 mb-1">
                                        Justification <span className="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={data.residual_justification}
                                        onChange={(e) => setData('residual_justification', e.target.value)}
                                        placeholder="Why the derived score does not reflect the risk…"
                                        className={INPUT}
                                    />
                                    <p className="text-xs text-gray-500 mt-1">
                                        An override without a reason is not a judgement, it is an unexplained number.
                                    </p>
                                    <InputError message={errors.residual_justification} className="mt-1" />
                                </div>
                            </div>
                        )}
                    </Card>
                )}

                {/* ---- Stage 5 — Steps 9-13: treatment, actions, KRIs ---- */}
                {stage === 4 && (
                    <div className="space-y-6">
                        <Card title="Risk treatment" step="Step 9 — the response to the residual risk you have just derived.">
                            <div className="max-w-md">
                                <select
                                    value={data.treatment_strategy}
                                    onChange={(e) => setData('treatment_strategy', e.target.value)}
                                    className={SELECT}
                                >
                                    <option value="">Select a strategy</option>
                                    {strategies.map((strategy) => (
                                        <option key={strategy.value} value={strategy.value}>
                                            {strategy.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.treatment_strategy} className="mt-1" />
                            </div>
                        </Card>

                        <Card
                            title="Action plan"
                            step="Steps 10-12 — each action needs an owner and a due date. A titled action with neither is a wish."
                        >
                            <div className="space-y-4">
                                {data.actions.map((action, index) => (
                                    <div key={index} className="border border-gray-200 rounded-lg p-4 space-y-3">
                                        <input
                                            type="text"
                                            value={action.action_title ?? ''}
                                            onChange={(e) => setAction(index, 'action_title', e.target.value)}
                                            placeholder="What will be done?"
                                            className={INPUT}
                                        />
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <div>
                                                <select
                                                    value={action.owner_id ?? ''}
                                                    onChange={(e) => setAction(index, 'owner_id', e.target.value)}
                                                    className={SELECT}
                                                >
                                                    <option value="">Owner…</option>
                                                    {users.map((user) => (
                                                        <option key={user.id} value={user.id}>
                                                            {user.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <InputError message={errors[`actions.${index}.owner_id`]} className="mt-1" />
                                            </div>
                                            <div>
                                                <input
                                                    type="date"
                                                    value={action.target_date ?? ''}
                                                    onChange={(e) => setAction(index, 'target_date', e.target.value)}
                                                    className={INPUT}
                                                />
                                                <InputError message={errors[`actions.${index}.target_date`]} className="mt-1" />
                                            </div>
                                            <select
                                                value={action.priority ?? 'medium'}
                                                onChange={(e) => setAction(index, 'priority', e.target.value)}
                                                className={SELECT}
                                            >
                                                {['low', 'medium', 'high', 'critical'].map((priority) => (
                                                    <option key={priority} value={priority}>
                                                        {ucfirst(priority)}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <button type="button" onClick={addAction} className="btn-secondary text-sm mt-4 inline-flex items-center gap-1">
                                <span className="material-symbols-outlined text-sm">add</span> Add action
                            </button>
                        </Card>

                        <Card
                            title="Key risk indicators"
                            step="Step 13 — what will tell you this risk is moving before it materialises again."
                        >
                            {[...linkedKris, ...availableKris].length === 0 ? (
                                <p className="text-sm text-gray-500">No indicators are available to attach.</p>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    {[...linkedKris, ...availableKris].map((kri) => (
                                        <label
                                            key={kri.id}
                                            className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={data.kri_ids.includes(kri.id)}
                                                onChange={() => toggleKri(kri.id)}
                                                className="rounded border-gray-300"
                                            />
                                            <span className="text-sm text-gray-700">
                                                <span className="font-medium">{kri.kri_code}</span> — {kri.kri_name}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}
                        </Card>

                        <Card title="Assessment rationale">
                            <textarea
                                rows={4}
                                value={data.rationale}
                                onChange={(e) => setData('rationale', e.target.value)}
                                placeholder="The reasoning a reviewer needs to follow this assessment…"
                                className={INPUT}
                            />
                            <InputError message={errors.rationale} className="mt-1" />

                            <textarea
                                rows={3}
                                value={data.recommendations}
                                onChange={(e) => setData('recommendations', e.target.value)}
                                placeholder="Recommendations (optional)"
                                className={`${INPUT} mt-4`}
                            />
                        </Card>
                    </div>
                )}

                {/* ---- Footer: the derived numbers travel with the assessor ---- */}
                <div className="sticky bottom-0 mt-6 bg-white border border-gray-200 rounded-xl p-4 flex flex-wrap items-center justify-between gap-4 shadow-sm">
                    <div className="flex items-center gap-6 text-sm">
                        <span className="text-gray-500">
                            Inherent{' '}
                            <span className="font-semibold text-[#1A365D]">
                                {inherent.score ?? '—'}
                            </span>
                        </span>
                        <span className="text-gray-500">
                            Residual{' '}
                            <span className="font-semibold text-[#1A365D]">{residual?.score ?? '—'}</span>
                            {preview?.isOverride && <span className="text-amber-600 text-xs ml-1">override</span>}
                        </span>
                        {previousAssessment && (
                            <span className="text-gray-400 text-xs">
                                Last approved {previousAssessment.overall_score} → {previousAssessment.residual_score}
                            </span>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href={route('risk.assessments.index')} className="btn-secondary text-sm">Cancel</Link>
                        <button
                            type="button"
                            onClick={(e) => submit(e, 'draft')}
                            disabled={processing}
                            className="btn-secondary text-sm disabled:opacity-50"
                        >
                            Save draft
                        </button>
                        <button
                            type="button"
                            onClick={(e) => submit(e, 'submit')}
                            disabled={processing}
                            className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50"
                        >
                            <span className="material-symbols-outlined text-lg">send</span> Submit for review
                        </button>
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
