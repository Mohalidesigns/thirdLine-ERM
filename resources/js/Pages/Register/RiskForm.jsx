import DynamicForm, { formDataFor, initialValues } from '@thirdline/ui/Components/DynamicForm';
import InputError from '@thirdline/ui/Components/InputError';

/**
 * The four sections shared by risk/register/create.blade.php and edit.blade.php
 * (migration Phase 3.2), plus the tenant-configured fields as section 5.
 *
 * `description` and `notes` stay plain textareas rather than becoming
 * RichTextEditors as the phase prompt's default suggests. Three things read
 * this column as text: the register grid excerpts it to 80 characters, the
 * search index tokenises it, and the AI Risk Statement Builder writes a plain
 * "Cause / Event / Consequence" block into it. Editor.js JSON in any of those
 * places is a regression, and none of them is this module's to change.
 */
const SECTION_HEADS = [
    'Risk Identification',
    'Risk Assessment (5x5 Matrix)',
    'Treatment & Classification',
    'Regulatory Alignment & Notes',
];

function Section({ index, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div className="flex items-center gap-2 mb-6">
                <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">
                    {index + 1}
                </div>
                <h2 className="text-lg font-semibold text-[#1A365D]">{SECTION_HEADS[index]}</h2>
            </div>
            {children}
        </div>
    );
}

function Field({ label, required = false, error, hint, children }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

const INPUT = 'w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
const SELECT = `${INPUT} bg-white`;

function Options({ rows, valueKey = 'id', labelKey = 'name' }) {
    return rows.map((row) => (
        <option key={row[valueKey] ?? row} value={row[valueKey] ?? row}>
            {row[labelKey] ?? row}
        </option>
    ));
}

/** Title-case a lowercase enum value for display, as `ucfirst()` did in Blade. */
export const ucfirst = (value) => (value ? value.charAt(0).toUpperCase() + value.slice(1) : '');

export default function RiskForm({
    data,
    setData,
    errors,
    categories = [],
    businessUnits = [],
    processes = [],
    users = [],
    options = {},
    schema = null,
    showRiskType = false,
    statusRequired = false,
}) {
    const set = (field) => (e) => setData(field, e.target.value);

    // The score preview mirrors the create path's arithmetic: likelihood times
    // the largest impact dimension. It is a preview only — the stored score is
    // whatever RiskRegisterService computes, which on a tenant with a
    // non-default scoring profile can differ. Labelled accordingly.
    const impacts = (options.impactDimensions ?? [])
        .map((dim) => Number(data[dim.field]) || 0)
        .filter((value) => value > 0);
    const likelihood = Number(data.inherent_likelihood) || 0;
    const previewScore = impacts.length > 0 && likelihood > 0 ? likelihood * Math.max(...impacts) : null;

    const toggleFramework = (framework) => {
        const current = data.regulatory_tags ?? [];

        setData(
            'regulatory_tags',
            current.includes(framework) ? current.filter((f) => f !== framework) : [...current, framework],
        );
    };

    return (
        <>
            <Section index={0}>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="md:col-span-2">
                        <Field label="Risk Title" required error={errors.title}>
                            <input type="text" value={data.title ?? ''} onChange={set('title')} className={INPUT} maxLength={255} />
                        </Field>
                    </div>

                    <div className="md:col-span-2">
                        <Field label="Risk Description" required error={errors.description}>
                            <textarea rows={4} value={data.description ?? ''} onChange={set('description')} className={INPUT} />
                        </Field>
                    </div>

                    <Field label="Risk Category" required error={errors.category_id}>
                        <select value={data.category_id ?? ''} onChange={set('category_id')} className={SELECT}>
                            <option value="">Select Category</option>
                            <Options rows={categories} />
                        </select>
                    </Field>

                    <Field label="Business Unit" required error={errors.business_unit_id}>
                        <select value={data.business_unit_id ?? ''} onChange={set('business_unit_id')} className={SELECT}>
                            <option value="">Select Business Unit</option>
                            <Options rows={businessUnits} />
                        </select>
                    </Field>

                    <Field label="Business Process" error={errors.process_id}>
                        <select value={data.process_id ?? ''} onChange={set('process_id')} className={SELECT}>
                            <option value="">Select Process</option>
                            <Options rows={processes} />
                        </select>
                    </Field>

                    <Field label="Risk Source" error={errors.risk_source}>
                        <select value={data.risk_source ?? ''} onChange={set('risk_source')} className={SELECT}>
                            <option value="">Select Source</option>
                            <Options rows={options.riskSources ?? []} />
                        </select>
                    </Field>

                    <Field label="Risk Owner" required error={errors.risk_owner_id}>
                        <select value={data.risk_owner_id ?? ''} onChange={set('risk_owner_id')} className={SELECT}>
                            <option value="">Select Owner</option>
                            <Options rows={users} />
                        </select>
                    </Field>

                    <Field label="Risk Steward" error={errors.risk_steward_id}>
                        <select value={data.risk_steward_id ?? ''} onChange={set('risk_steward_id')} className={SELECT}>
                            <option value="">Select Steward</option>
                            <Options rows={users} />
                        </select>
                    </Field>

                    <Field label="Date Identified" error={errors.date_identified}>
                        <input type="date" value={data.date_identified ?? ''} onChange={set('date_identified')} className={INPUT} />
                    </Field>

                    <Field label="Identified By" error={errors.identified_by}>
                        <select value={data.identified_by ?? ''} onChange={set('identified_by')} className={SELECT}>
                            <option value="">Select User</option>
                            <Options rows={users} />
                        </select>
                    </Field>
                </div>
            </Section>

            <Section index={1}>
                <div className="mb-6 max-w-md">
                    <Field label="Likelihood (1-5)" required error={errors.inherent_likelihood}>
                        <select value={data.inherent_likelihood ?? ''} onChange={set('inherent_likelihood')} className={SELECT}>
                            <option value="">Select Likelihood</option>
                            <Options rows={options.likelihood ?? []} valueKey="value" labelKey="label" />
                        </select>
                    </Field>
                </div>

                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                    <h4 className="text-sm font-semibold text-[#1A365D] mb-4">Multi-Dimension Impact Assessment</h4>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {(options.impactDimensions ?? []).map((dim) => (
                            <Field key={dim.field} label={dim.label} required error={errors[dim.field]} hint={dim.hint}>
                                <select value={data[dim.field] ?? ''} onChange={set(dim.field)} className={SELECT}>
                                    <option value="">Select</option>
                                    <Options rows={options.impact ?? []} valueKey="value" labelKey="label" />
                                </select>
                            </Field>
                        ))}
                    </div>
                </div>

                <div className="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p className="text-sm text-gray-600">Inherent Risk Score</p>
                    {previewScore === null ? (
                        <p className="text-xs text-gray-500">
                            Auto-calculated on save: Likelihood x Impact = Score /25
                        </p>
                    ) : (
                        <>
                            <p className="text-2xl font-bold text-[#1A365D]">{previewScore}/25</p>
                            <p className="text-xs text-gray-500">
                                Preview. The stored score uses your organisation&rsquo;s scoring profile, which may
                                combine the impact dimensions differently.
                            </p>
                        </>
                    )}
                </div>
            </Section>

            <Section index={2}>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <Field label="Treatment Strategy" error={errors.treatment_strategy}>
                        <select value={data.treatment_strategy ?? ''} onChange={set('treatment_strategy')} className={SELECT}>
                            <option value="">Select Strategy</option>
                            {(options.treatmentStrategies ?? []).map((value) => (
                                <option key={value} value={value}>{ucfirst(value)}</option>
                            ))}
                        </select>
                    </Field>

                    {showRiskType && (
                        <Field label="Risk Type" error={errors.risk_type}>
                            <select value={data.risk_type ?? ''} onChange={set('risk_type')} className={SELECT}>
                                <option value="">Select Type</option>
                                {(options.riskTypes ?? []).map((value) => (
                                    <option key={value} value={value}>{ucfirst(value)}</option>
                                ))}
                            </select>
                        </Field>
                    )}

                    <Field label="Status" required={statusRequired} error={errors.status}>
                        <select value={data.status ?? ''} onChange={set('status')} className={SELECT}>
                            {!statusRequired && <option value="">Select Status</option>}
                            {(options.statuses ?? []).map((value) => (
                                <option key={value} value={value}>{ucfirst(value)}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Risk Velocity" error={errors.risk_velocity}>
                        <select value={data.risk_velocity ?? ''} onChange={set('risk_velocity')} className={SELECT}>
                            <option value="">Select Velocity</option>
                            {(options.velocities ?? []).map((value) => (
                                <option key={value} value={value}>{ucfirst(value)}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Review Frequency" error={errors.review_frequency}>
                        <select value={data.review_frequency ?? ''} onChange={set('review_frequency')} className={SELECT}>
                            <option value="">Select Frequency</option>
                            {(options.reviewFrequencies ?? []).map((value) => (
                                <option key={value} value={value}>{ucfirst(value)}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Financial Exposure (NGN)" error={errors.financial_exposure}>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.financial_exposure ?? ''}
                            onChange={set('financial_exposure')}
                            className={INPUT}
                        />
                    </Field>
                </div>
            </Section>

            <Section index={3}>
                <div className="mb-6">
                    <label className="block text-sm font-medium text-gray-700 mb-3">Regulatory Frameworks</label>
                    <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
                        {(options.frameworks ?? []).map((framework) => (
                            <label
                                key={framework}
                                className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer transition-colors"
                            >
                                <input
                                    type="checkbox"
                                    checked={(data.regulatory_tags ?? []).includes(framework)}
                                    onChange={() => toggleFramework(framework)}
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                />
                                <span className="text-sm font-medium text-gray-700">{framework}</span>
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.regulatory_tags} className="mt-1" />
                </div>

                <Field label="Additional Notes" error={errors.notes}>
                    <textarea rows={3} value={data.notes ?? ''} onChange={set('notes')} className={INPUT} />
                </Field>
            </Section>

            {(schema?.sections?.length ?? 0) > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <div className="flex items-center gap-2 mb-6">
                        <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">5</div>
                        <h2 className="text-lg font-semibold text-[#1A365D]">Additional Fields</h2>
                    </div>
                    <DynamicForm
                        schema={schema}
                        values={data.configured_attributes ?? {}}
                        errors={errors}
                        onChange={(code, value) =>
                            setData('configured_attributes', { ...(data.configured_attributes ?? {}), [code]: value })
                        }
                    />
                </div>
            )}
        </>
    );
}

/** Seed `configured_attributes` from the schema, keeping anything already typed. */
export function seedConfigured(schema, current = {}) {
    return { ...initialValues(schema ?? { sections: [] }), ...current };
}

/** Flatten the configured fields into the shape the server reads. */
export function toPayload(data, schema) {
    const { configured_attributes: configured, ...rest } = data;

    return { ...rest, ...(schema ? formDataFor(schema, configured ?? {}) : {}) };
}
