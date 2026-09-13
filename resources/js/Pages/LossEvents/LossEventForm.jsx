import InputError from '@thirdline/ui/Components/InputError';
import { naira, titleCase } from './format';

/**
 * The report and amend form for a loss event (migration Phase 4.3).
 *
 * The Blade create page was a three-step wizard driven by `goToStep()` and a
 * hidden `step` query parameter the controller read and never used for
 * anything but highlighting a circle. The steps are kept — the form is long
 * enough to want them — but they are component state, so nothing round-trips
 * to the server to change tab, and the running net-loss figure at the bottom
 * updates as the numbers are typed rather than on submit.
 */
const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

export const STEPS = [
    { step: 1, label: 'Basic Information', icon: 'info' },
    { step: 2, label: 'Classification', icon: 'category' },
    { step: 3, label: 'Financial Impact', icon: 'payments' },
];

/** Which fields belong to which step, so a step can show its own error count. */
const STEP_FIELDS = {
    1: ['event_title', 'event_description', 'date_of_loss', 'date_discovered', 'business_unit_id', 'reported_by', 'risk_id'],
    2: ['basel_event_type', 'cbn_loss_category', 'event_type', 'severity', 'root_cause_summary', 'corrective_action_summary'],
    3: ['gross_loss_amount', 'recovery_amount', 'insurance_recovery', 'is_near_miss', 'is_regulatory_reportable', 'regulatory_body', 'reporting_deadline'],
};

export function errorsInStep(errors, step) {
    return STEP_FIELDS[step].filter((field) => errors[field]).length;
}

function Field({ label, required = false, error, help, children, className = '' }) {
    return (
        <div className={className}>
            <label className="block text-xs font-medium text-gray-600 mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
            {help && <p className="text-xs text-gray-500 mt-1">{help}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

export default function LossEventForm({
    step,
    data,
    setData,
    errors = {},
    risks = [],
    businessUnits = [],
    users = [],
    options = {},
    showReporter = false,
    showNearMiss = false,
}) {
    const text = (name) => (e) => setData(name, e.target.value);

    const gross = Number(data.gross_loss_amount) || 0;
    const insurance = Number(data.insurance_recovery) || 0;
    const other = Number(data.recovery_amount) || 0;
    const net = gross - insurance - other;

    return (
        <>
            {step === 1 && (
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-base font-bold text-[#1A365D] mb-6">Basic Information</h2>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Field label="Event Title" required error={errors.event_title} className="lg:col-span-2">
                            <input type="text" value={data.event_title ?? ''} onChange={text('event_title')} className={INPUT} />
                        </Field>

                        <Field label="What Happened" required error={errors.event_description} className="lg:col-span-2">
                            <textarea rows={4} value={data.event_description ?? ''} onChange={text('event_description')} className={INPUT} />
                        </Field>

                        <Field label="Date of Loss" required error={errors.date_of_loss}>
                            <input type="date" value={data.date_of_loss ?? ''} onChange={text('date_of_loss')} className={INPUT} />
                        </Field>

                        <Field
                            label="Date Discovered"
                            required
                            error={errors.date_discovered}
                            help="Cannot be before the date of loss."
                        >
                            <input type="date" value={data.date_discovered ?? ''} onChange={text('date_discovered')} className={INPUT} />
                        </Field>

                        <Field label="Business Unit" required error={errors.business_unit_id}>
                            <select value={data.business_unit_id ?? ''} onChange={text('business_unit_id')} className={INPUT}>
                                <option value="">Select a unit</option>
                                {businessUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                            </select>
                        </Field>

                        {showReporter && (
                            <Field label="Reported By" required error={errors.reported_by}>
                                <select value={data.reported_by ?? ''} onChange={text('reported_by')} className={INPUT}>
                                    <option value="">Select</option>
                                    {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                                </select>
                            </Field>
                        )}

                        <Field label="Linked Risk" error={errors.risk_id} className="lg:col-span-2" help="Optional — links the event to the register entry it realised.">
                            <select value={data.risk_id ?? ''} onChange={text('risk_id')} className={INPUT}>
                                <option value="">Not linked</option>
                                {risks.map((risk) => <option key={risk.id} value={risk.id}>{risk.code} — {risk.title}</option>)}
                            </select>
                        </Field>
                    </div>
                </div>
            )}

            {step === 2 && (
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-base font-bold text-[#1A365D] mb-6">Classification</h2>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Field
                            label="Basel Event Type"
                            required
                            error={errors.basel_event_type}
                            help="Drives the CBN, NFIU and EFCC alerts — a fraud event is classified here."
                        >
                            <select value={data.basel_event_type ?? ''} onChange={text('basel_event_type')} className={INPUT}>
                                <option value="">Select</option>
                                {(options.baselEventTypes ?? []).map((type) => (
                                    <option key={type} value={type}>{titleCase(type)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Event Type" required error={errors.event_type}>
                            <select value={data.event_type ?? ''} onChange={text('event_type')} className={INPUT}>
                                <option value="">Select</option>
                                {(options.eventTypes ?? []).map((type) => (
                                    <option key={type} value={type}>{titleCase(type)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="CBN Loss Category" error={errors.cbn_loss_category}>
                            <input type="text" value={data.cbn_loss_category ?? ''} onChange={text('cbn_loss_category')} className={INPUT} />
                        </Field>

                        <Field label="Severity" required error={errors.severity}>
                            <select value={data.severity ?? ''} onChange={text('severity')} className={INPUT}>
                                <option value="">Select</option>
                                {(options.severities ?? []).map((severity) => (
                                    <option key={severity} value={severity}>{titleCase(severity)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Initial Root Cause" error={errors.root_cause_summary} className="lg:col-span-2" help="A first view — the full analysis is recorded on the event afterwards.">
                            <textarea rows={3} value={data.root_cause_summary ?? ''} onChange={text('root_cause_summary')} className={INPUT} />
                        </Field>

                        <Field label="Corrective Action" error={errors.corrective_action_summary} className="lg:col-span-2">
                            <textarea rows={3} value={data.corrective_action_summary ?? ''} onChange={text('corrective_action_summary')} className={INPUT} />
                        </Field>
                    </div>
                </div>
            )}

            {step === 3 && (
                <>
                    <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                        <h2 className="text-base font-bold text-[#1A365D] mb-6">Financial Impact</h2>

                        {showNearMiss && (
                            <label className="flex items-start gap-3 mb-6 p-3 rounded-lg bg-gray-50">
                                <input
                                    type="checkbox"
                                    checked={Boolean(data.is_near_miss)}
                                    onChange={(e) => setData('is_near_miss', e.target.checked)}
                                    className="rounded border-gray-300 text-[#1A365D] mt-0.5"
                                />
                                <span className="text-sm text-gray-700">
                                    This was a near miss
                                    <span className="block text-xs text-gray-500 mt-0.5">
                                        The amounts below are set to zero and the event is classified as a near miss.
                                    </span>
                                </span>
                            </label>
                        )}

                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <Field label="Gross Loss" required error={errors.gross_loss_amount}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    disabled={Boolean(data.is_near_miss)}
                                    value={data.is_near_miss ? 0 : (data.gross_loss_amount ?? '')}
                                    onChange={text('gross_loss_amount')}
                                    className={`${INPUT} disabled:bg-gray-50`}
                                />
                            </Field>

                            <Field label="Other Recovery" error={errors.recovery_amount}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    disabled={Boolean(data.is_near_miss)}
                                    value={data.is_near_miss ? 0 : (data.recovery_amount ?? '')}
                                    onChange={text('recovery_amount')}
                                    className={`${INPUT} disabled:bg-gray-50`}
                                />
                            </Field>

                            <Field label="Insurance Recovery" error={errors.insurance_recovery}>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    disabled={Boolean(data.is_near_miss)}
                                    value={data.is_near_miss ? 0 : (data.insurance_recovery ?? '')}
                                    onChange={text('insurance_recovery')}
                                    className={`${INPUT} disabled:bg-gray-50`}
                                />
                            </Field>
                        </div>

                        <div className="mt-4 rounded-lg bg-gray-50 px-4 py-3 flex items-center justify-between">
                            <span className="text-xs text-gray-600">Net loss after recoveries</span>
                            <span className={`text-sm font-semibold ${net > 0 ? 'text-red-700' : 'text-green-700'}`}>
                                {naira(data.is_near_miss ? 0 : net)}
                            </span>
                        </div>
                        {net < 0 && !data.is_near_miss && (
                            <p className="text-xs text-amber-600 mt-2">
                                Recoveries exceed the gross loss — worth checking before submitting.
                            </p>
                        )}
                    </div>

                    <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                        <h2 className="text-base font-bold text-[#1A365D] mb-6">Regulatory Reporting</h2>

                        <label className="flex items-start gap-3 mb-4">
                            <input
                                type="checkbox"
                                checked={Boolean(data.is_regulatory_reportable)}
                                onChange={(e) => setData('is_regulatory_reportable', e.target.checked)}
                                className="rounded border-gray-300 text-[#1A365D] mt-0.5"
                            />
                            <span className="text-sm text-gray-700">
                                Reportable to a regulator
                                <span className="block text-xs text-gray-500 mt-0.5">
                                    Left unticked, this is decided from the gross loss against the configured threshold.
                                </span>
                            </span>
                        </label>

                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <Field label="Regulatory Body" error={errors.regulatory_body}>
                                <input type="text" value={data.regulatory_body ?? ''} onChange={text('regulatory_body')} className={INPUT} />
                            </Field>
                            <Field label="Reporting Deadline" error={errors.reporting_deadline}>
                                <input type="date" value={data.reporting_deadline ?? ''} onChange={text('reporting_deadline')} className={INPUT} />
                            </Field>
                        </div>
                    </div>
                </>
            )}
        </>
    );
}
