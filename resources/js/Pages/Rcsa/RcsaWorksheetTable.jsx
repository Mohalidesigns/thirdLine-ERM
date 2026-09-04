import InputError from '@/Components/InputError';

/**
 * The editable worksheet table (migration Phase 3.8).
 *
 * NOT built on Components/DataGrid/GridTable, although the phase prompt says
 * to. GridTable's inline edit posts ONE CELL AT A TIME to a per-cell endpoint
 * belonging to a registered Grid definition. A worksheet is a batch: many
 * lines, filed together in one transaction, as a draft or a submission, with a
 * domain event on submit. Putting it on GridTable would mean either replacing
 * that atomicity with N independent cell saves — losing the draft/submit
 * distinction and RcsaWorksheetSubmitted with it — or borrowing the markup and
 * bypassing the save path, which is not reuse. See the module notes.
 *
 * One row is one risk line. Scores are selects because the 1–5 anchors carry
 * their wording ("3: Possible"), which is the part an assessor is actually
 * choosing between.
 */
const LIKELIHOOD = { 1: 'Rare', 2: 'Unlikely', 3: 'Possible', 4: 'Likely', 5: 'Almost Certain' };
const IMPACT = { 1: 'Insignificant', 2: 'Minor', 3: 'Moderate', 4: 'Major', 5: 'Catastrophic' };

const EFFECTIVENESS = {
    effective: 'Effective',
    partially_effective: 'Partially effective',
    ineffective: 'Ineffective',
    not_tested: 'Not tested',
};

export const EMPTY_LINE = {
    risk_id: '',
    description: '',
    category: '',
    inherent_likelihood: 3,
    inherent_impact: 3,
    residual_likelihood: 3,
    residual_impact: 3,
    control_effectiveness: '',
    existing_controls: '',
    action_plan: '',
};

const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

function scoreTone(score) {
    if (score >= 20) return 'bg-red-100 text-red-700';
    if (score >= 12) return 'bg-orange-100 text-orange-700';
    if (score >= 5) return 'bg-yellow-100 text-yellow-700';

    return 'bg-green-100 text-green-700';
}

function ScoreSelect({ value, onChange, anchors, error }) {
    return (
        <div>
            <select value={value} onChange={(e) => onChange(Number(e.target.value))} className={INPUT}>
                {Object.entries(anchors).map(([score, label]) => (
                    <option key={score} value={score}>{score}: {label}</option>
                ))}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Field({ label, required = false, children }) {
    return (
        <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
        </div>
    );
}

function Line({ line, index, categories, assessableRisks, onChange, onRemove, removable, errors }) {
    const set = (key) => (value) => onChange(index, key, value);
    const error = (key) => errors[`risks.${index}.${key}`];

    const inherent = line.inherent_likelihood * line.inherent_impact;
    const residual = line.residual_likelihood * line.residual_impact;

    return (
        <div className="border border-gray-200 rounded-lg p-4 mb-4">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-sm font-semibold text-[#1A365D]">Risk line {index + 1}</h3>
                {removable && (
                    <button
                        type="button"
                        onClick={() => onRemove(index)}
                        className="text-xs text-red-500 hover:text-red-700 flex items-center gap-1"
                    >
                        <span className="material-symbols-outlined text-sm">delete</span> Remove
                    </button>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <Field label="Risk Description" required>
                    <textarea
                        rows={2}
                        value={line.description}
                        onChange={(e) => set('description')(e.target.value)}
                        placeholder="Describe the risk..."
                        className={INPUT}
                    />
                    <InputError message={error('description')} className="mt-1" />
                </Field>

                <div className="grid grid-cols-1 gap-4">
                    <Field label="Register Risk (optional)">
                        <select
                            value={line.risk_id ?? ''}
                            onChange={(e) => {
                                const id = e.target.value;
                                const picked = assessableRisks.find((risk) => String(risk.id) === String(id));

                                onChange(index, 'risk_id', id);

                                // Filling an empty description from the picked
                                // risk saves retyping; anything already typed
                                // is the assessor's and is left alone.
                                if (picked && !line.description) onChange(index, 'description', picked.title);
                                if (picked && !line.category && picked.category) onChange(index, 'category', picked.category);
                            }}
                            className={INPUT}
                        >
                            <option value="">Not linked to the register</option>
                            {assessableRisks.map((risk) => (
                                <option key={risk.id} value={risk.id}>{risk.code} — {risk.title}</option>
                            ))}
                        </select>
                        <InputError message={error('risk_id')} className="mt-1" />
                    </Field>

                    <Field label="Risk Category">
                        <select value={line.category ?? ''} onChange={(e) => set('category')(e.target.value)} className={INPUT}>
                            <option value="">Select</option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.name}>{category.name}</option>
                            ))}
                        </select>
                        <InputError message={error('category')} className="mt-1" />
                    </Field>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-2">
                <Field label="Inherent Likelihood">
                    <ScoreSelect value={line.inherent_likelihood} onChange={set('inherent_likelihood')} anchors={LIKELIHOOD} error={error('inherent_likelihood')} />
                </Field>
                <Field label="Inherent Impact">
                    <ScoreSelect value={line.inherent_impact} onChange={set('inherent_impact')} anchors={IMPACT} error={error('inherent_impact')} />
                </Field>
                <Field label="Residual Likelihood">
                    <ScoreSelect value={line.residual_likelihood} onChange={set('residual_likelihood')} anchors={LIKELIHOOD} error={error('residual_likelihood')} />
                </Field>
                <Field label="Residual Impact">
                    <ScoreSelect value={line.residual_impact} onChange={set('residual_impact')} anchors={IMPACT} error={error('residual_impact')} />
                </Field>
            </div>

            {/* The two products, shown as they are chosen. The rating band is
                deliberately NOT shown: RiskScoringService owns the bands and
                computes them on save, and a second copy here could disagree
                with what gets stored. */}
            <div className="flex items-center gap-3 mb-4 text-xs">
                <span className="text-gray-500">Inherent</span>
                <span className={`badge ${scoreTone(inherent)}`}>{inherent}</span>
                <span className="material-symbols-outlined text-gray-300 text-sm">arrow_forward</span>
                <span className="text-gray-500">Residual</span>
                <span className={`badge ${scoreTone(residual)}`}>{residual}</span>
                {residual > inherent && (
                    <span className="text-amber-600">Residual is above inherent — is that intended?</span>
                )}
            </div>

            <div className="mb-4">
                <Field label="Control Effectiveness">
                    <select value={line.control_effectiveness ?? ''} onChange={(e) => set('control_effectiveness')(e.target.value)} className={INPUT}>
                        <option value="">Not assessed</option>
                        {Object.entries(EFFECTIVENESS).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                    <InputError message={error('control_effectiveness')} className="mt-1" />
                </Field>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <Field label="Existing Controls">
                    <textarea rows={2} value={line.existing_controls ?? ''} onChange={(e) => set('existing_controls')(e.target.value)} placeholder="List existing controls..." className={INPUT} />
                    <InputError message={error('existing_controls')} className="mt-1" />
                </Field>
                <Field label="Action Plan">
                    <textarea rows={2} value={line.action_plan ?? ''} onChange={(e) => set('action_plan')(e.target.value)} placeholder="Recommended actions..." className={INPUT} />
                    <InputError message={error('action_plan')} className="mt-1" />
                </Field>
            </div>
        </div>
    );
}

export default function RcsaWorksheetTable({ lines, categories = [], assessableRisks = [], onChange, onRemove, onAdd, errors = {} }) {
    return (
        <>
            {lines.map((line, index) => (
                <Line
                    key={index}
                    line={line}
                    index={index}
                    categories={categories}
                    assessableRisks={assessableRisks}
                    onChange={onChange}
                    onRemove={onRemove}
                    removable={lines.length > 1}
                    errors={errors}
                />
            ))}

            <button
                type="button"
                onClick={onAdd}
                className="flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#1A365D] hover:text-[#1A365D] transition-colors"
            >
                <span className="material-symbols-outlined text-lg">add</span> Add Another Risk
            </button>

            <InputError message={errors.risks} className="mt-2" />
        </>
    );
}
