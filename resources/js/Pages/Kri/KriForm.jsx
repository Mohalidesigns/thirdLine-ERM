import { Children, cloneElement, isValidElement, useId } from 'react';
import DynamicForm from '@thirdline/ui/Components/DynamicForm';
import InputError from '@thirdline/ui/Components/InputError';
import KriBandLegend from '@/Components/Kri/KriBandLegend';
import RedBandSection from '@/Components/Kri/RedBandSection';

/**
 * The create and edit form for a KRI (migration Phase 4.1).
 *
 * TWO THRESHOLD INPUTS, NOT THREE. A traffic-light band set needs two
 * boundaries: on a higher-is-worse indicator green runs up to the green
 * boundary, red starts at the red boundary, and amber is whatever lies between
 * — it has no boundary of its own. The Blade form asked for an amber number
 * too; the controller read it into a variable and never used it, and the
 * model's amber accessor has always returned red's edge, so the box redisplayed
 * red's value. The amber band is shown here as the derived range it is.
 */
const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

const FREQUENCY_LABELS = {
    daily: 'Daily',
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semi_annually: 'Semi-annually',
};

const DIRECTION_LABELS = {
    higher_is_worse: 'Higher is worse — the value rising is bad',
    lower_is_worse: 'Lower is worse — the value falling is bad',
};

function Section({ index, title, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div className="flex items-center gap-2 mb-6">
                <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">{index}</div>
                <h2 className="text-lg font-semibold text-[#1A365D]">{title}</h2>
            </div>
            {children}
        </div>
    );
}

/**
 * A labelled field. The label is bound to the control, a required field says so
 * to a screen reader as well as with the star, and an error or help line is
 * referenced from the control (aria-describedby) so it is read with it.
 * (The previous version bound nothing; 00-common section 5 requires it of every
 * form this work touches.)
 */
function Field({ label, required = false, error, help, children, className = '' }) {
    const uid = useId();
    const controlId = `${uid}-control`;
    const helpId = help ? `${uid}-help` : null;
    const errorId = error ? `${uid}-error` : null;
    const describedBy = [helpId, errorId].filter(Boolean).join(' ') || undefined;

    const control = Children.map(children, (child) => (
        isValidElement(child)
            ? cloneElement(child, {
                id: child.props.id ?? controlId,
                'aria-invalid': error ? 'true' : undefined,
                'aria-describedby': describedBy,
                ...(required ? { required: true, 'aria-required': true } : {}),
            })
            : child
    ));
    const forId = Children.toArray(children).find(isValidElement)?.props.id ?? controlId;

    return (
        <div className={className}>
            <label htmlFor={forId} className="block text-xs font-medium text-gray-600 mb-1">
                {label}
                {required && (
                    <>
                        {' '}
                        <span aria-hidden="true" className="text-red-500">*</span>
                        <span className="sr-only"> (required)</span>
                    </>
                )}
            </label>
            {control}
            {help && <p id={helpId} className="text-xs text-gray-600 mt-1">{help}</p>}
            <InputError message={error} id={errorId} className="mt-1" />
        </div>
    );
}

export default function KriForm({
    data,
    setData,
    errors = {},
    schema,
    risks = [],
    users = [],
    departments = [],
    frequencies = [],
    directions = [],
    showRisk = false,
    showActive = false,
    isEdit = false,
    redBand = null,
}) {
    return (
        <>
            <Section index={1} title="Indicator">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {showRisk && (
                        <Field label="Risk Being Monitored" required error={errors.risk_id} className="lg:col-span-2">
                            <select value={data.risk_id ?? ''} onChange={(e) => setData('risk_id', e.target.value)} className={INPUT}>
                                <option value="">Select a risk</option>
                                {risks.map((risk) => (
                                    <option key={risk.id} value={risk.id}>{risk.code} — {risk.title}</option>
                                ))}
                            </select>
                        </Field>
                    )}

                    <Field label="KRI Name" required error={errors.kri_name} className="lg:col-span-2">
                        <input type="text" value={data.kri_name ?? ''} onChange={(e) => setData('kri_name', e.target.value)} className={INPUT} />
                    </Field>

                    <Field label="Description" error={errors.description} className="lg:col-span-2">
                        <textarea rows={3} value={data.description ?? ''} onChange={(e) => setData('description', e.target.value)} className={INPUT} />
                    </Field>

                    <Field label="Unit of Measure" required error={errors.measurement_unit} help="Shown beside every reading, e.g. % or days.">
                        <input type="text" value={data.measurement_unit ?? ''} onChange={(e) => setData('measurement_unit', e.target.value)} className={INPUT} />
                    </Field>

                    <Field label="Measurement Frequency" required error={errors.measurement_frequency}>
                        <select value={data.measurement_frequency ?? ''} onChange={(e) => setData('measurement_frequency', e.target.value)} className={INPUT}>
                            <option value="">Select</option>
                            {frequencies.map((frequency) => (
                                <option key={frequency} value={frequency}>{FREQUENCY_LABELS[frequency] ?? frequency}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Owner" required error={errors.kri_owner_id}>
                        <select value={data.kri_owner_id ?? ''} onChange={(e) => setData('kri_owner_id', e.target.value)} className={INPUT}>
                            <option value="">Select an owner</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>{user.name}</option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        label="Department"
                        error={errors.business_unit_id}
                        help={
                            <>
                                A KRI with a department is included in that department&apos;s monthly return. KRIs with no department aren&apos;t included and don&apos;t count on the submission dashboard.
                                {isEdit && ' Moving a KRI to another department takes it out of the old department\u2019s return the next time that return is saved or submitted. A draft value for it is discarded and recorded in the audit trail.'}
                            </>
                        }
                        className="lg:col-span-2"
                    >
                        <select value={data.business_unit_id ?? ''} onChange={(e) => setData('business_unit_id', e.target.value)} className={INPUT}>
                            <option value="">No department (Unassigned)</option>
                            {departments.map((department) => (
                                <option key={department.id} value={department.id}>{department.name}</option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Data Source" error={errors.data_source}>
                        <input type="text" value={data.data_source ?? ''} onChange={(e) => setData('data_source', e.target.value)} className={INPUT} />
                    </Field>

                    <Field label="Calculation" error={errors.formula} help="How the value is derived, in words or as an expression." className="lg:col-span-2">
                        <input type="text" value={data.formula ?? ''} onChange={(e) => setData('formula', e.target.value)} className={INPUT} />
                    </Field>
                </div>
            </Section>

            <Section index={2} title="Thresholds">
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div className="space-y-4">
                        <Field label="Direction" required error={errors.direction}>
                            <select value={data.direction ?? ''} onChange={(e) => setData('direction', e.target.value)} className={INPUT}>
                                <option value="">Select</option>
                                {directions.map((direction) => (
                                    <option key={direction} value={direction}>{DIRECTION_LABELS[direction] ?? direction}</option>
                                ))}
                            </select>
                        </Field>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Green Boundary" error={errors.green_threshold}>
                                <input type="number" step="0.01" value={data.green_threshold ?? ''} onChange={(e) => setData('green_threshold', e.target.value)} className={INPUT} />
                            </Field>
                            <Field label="Red Boundary" error={errors.red_threshold}>
                                <input type="number" step="0.01" value={data.red_threshold ?? ''} onChange={(e) => setData('red_threshold', e.target.value)} className={INPUT} />
                            </Field>
                        </div>

                        <Field label="Target Value" error={errors.target_value}>
                            <input
                                type="number"
                                step="0.01"
                                value={data.target_value ?? ''}
                                onChange={(e) => setData('target_value', e.target.value)}
                                onKeyDown={redBand ? (e) => redBand.onValueKeyDown(e, redBand.incomplete) : undefined}
                                onBlur={redBand ? redBand.flush : undefined}
                                className={INPUT}
                            />
                        </Field>

                        {showActive && (
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={Boolean(data.is_active)} onChange={(e) => setData('is_active', e.target.checked)} className="rounded border-gray-300 text-[#1A365D]" />
                                Actively monitored
                            </label>
                        )}
                    </div>

                    <div className="rounded-lg bg-gray-50 p-4">
                        <p className="text-xs font-semibold text-gray-700 mb-3">Resulting bands</p>
                        <KriBandLegend direction={data.direction} green={data.green_threshold} red={data.red_threshold} unit={data.measurement_unit} />
                    </div>
                </div>

                {redBand && redBand.liveRegion}
                {redBand?.show && (
                    <RedBandSection
                        idPrefix="kri-target-redband"
                        title="The target value is in the red band: explain it"
                        context="target"
                        values={redBand.values}
                        onChange={redBand.onChange}
                        errors={redBand.errors}
                        openIssue={redBand.openIssue}
                        openBreach={redBand.openBreach}
                        ownerOptions={redBand.ownerOptions}
                        lagosToday={redBand.lagosToday}
                        required={redBand.required}
                        sectionRef={redBand.sectionRef}
                        intro={redBand.required
                            ? 'Root cause, action point and a timeline are needed before this can be saved.'
                            : 'Not required for this save: the target did not move and the open Issue already explains it. Fill this in only to update the Issue; leave it empty to keep the Issue as it is.'}
                    />
                )}
            </Section>

            {(schema?.sections ?? []).length > 0 && (
                <Section index={3} title="Additional Fields">
                    <DynamicForm schema={schema} values={data} errors={errors} onChange={(code, value) => setData(code, value)} />
                </Section>
            )}
        </>
    );
}
