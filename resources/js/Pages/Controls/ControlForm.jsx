import DynamicForm from '@thirdline/ui/Components/DynamicForm';
import InputError from '@thirdline/ui/Components/InputError';

const INPUT =
    'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
const SELECT = `${INPUT} bg-white`;

/** Turn an enum value into the label the Blade selects showed. */
export const humanise = (value) =>
    value ? String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()) : '';

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

function EnumSelect({ value, onChange, options = [], placeholder }) {
    return (
        <select value={value ?? ''} onChange={onChange} className={SELECT}>
            <option value="">{placeholder}</option>
            {options.map((option) => (
                <option key={option} value={option}>
                    {humanise(option)}
                </option>
            ))}
        </select>
    );
}

/**
 * The control library form, shared by create and edit (migration Phase 3.4).
 *
 * Every vocabulary comes from the server as a model constant, so a select
 * cannot offer a value the Form Request rejects — which is how
 * `effectiveness_rating` came to show three options on this form while the
 * assessment screen records five.
 */
export default function ControlForm({
    data,
    setData,
    errors,
    businessUnits = [],
    users = [],
    risks = [],
    options = {},
    schema = null,
    showRiskLinks = false,
}) {
    const set = (field) => (e) => setData(field, e.target.value);

    const toggleRisk = (id) => {
        const current = data.risk_ids ?? [];

        setData('risk_ids', current.includes(id) ? current.filter((r) => r !== id) : [...current, id]);
    };

    return (
        <>
            <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <h2 className="text-lg font-semibold text-[#1A365D] mb-6">Control definition</h2>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="md:col-span-2">
                        <Field label="Control name" required error={errors.name}>
                            <input type="text" maxLength={200} value={data.name ?? ''} onChange={set('name')} className={INPUT} />
                        </Field>
                    </div>

                    <div className="md:col-span-2">
                        <Field label="Description" required error={errors.description}>
                            <textarea rows={4} value={data.description ?? ''} onChange={set('description')} className={INPUT} />
                        </Field>
                    </div>

                    <Field
                        label="Control type"
                        required
                        error={errors.control_type}
                        hint="Preventive and directive controls reduce likelihood; detective and corrective reduce impact."
                    >
                        <EnumSelect
                            value={data.control_type}
                            onChange={set('control_type')}
                            options={options.types}
                            placeholder="Select type"
                        />
                    </Field>

                    <Field label="Control nature" error={errors.control_nature}>
                        <EnumSelect
                            value={data.control_nature}
                            onChange={set('control_nature')}
                            options={options.natures}
                            placeholder="Select nature"
                        />
                    </Field>

                    <Field label="Frequency" error={errors.frequency}>
                        <EnumSelect
                            value={data.frequency}
                            onChange={set('frequency')}
                            options={options.frequencies}
                            placeholder="Select frequency"
                        />
                    </Field>

                    <Field label="Control owner" required error={errors.owner_id}>
                        <select value={data.owner_id ?? ''} onChange={set('owner_id')} className={SELECT}>
                            <option value="">Select owner</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.owner_id} className="mt-1" />
                    </Field>

                    <Field label="Business unit" error={errors.business_unit_id}>
                        <select value={data.business_unit_id ?? ''} onChange={set('business_unit_id')} className={SELECT}>
                            <option value="">Select business unit</option>
                            {businessUnits.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field
                        label="Effectiveness rating"
                        error={errors.effectiveness_rating}
                        hint="The library's standing rating. A test or an assessment can record a finer one."
                    >
                        <EnumSelect
                            value={data.effectiveness_rating}
                            onChange={set('effectiveness_rating')}
                            options={options.effectivenessRatings}
                            placeholder="Not rated"
                        />
                    </Field>

                    <Field label="Status" error={errors.status}>
                        <EnumSelect
                            value={data.status}
                            onChange={set('status')}
                            options={options.statuses}
                            placeholder="Select status"
                        />
                    </Field>
                </div>
            </div>

            {showRiskLinks && (
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-lg font-semibold text-[#1A365D] mb-1">Risks this control mitigates</h2>
                    <p className="text-sm text-gray-500 mb-5">
                        Optional. Mappings can be added and removed later from the control's own page.
                    </p>

                    {risks.length === 0 ? (
                        <p className="text-sm text-gray-500">No risks are registered yet.</p>
                    ) : (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-2 max-h-72 overflow-y-auto">
                            {risks.map((risk) => (
                                <label
                                    key={risk.id}
                                    className="flex items-start gap-3 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        checked={(data.risk_ids ?? []).includes(risk.id)}
                                        onChange={() => toggleRisk(risk.id)}
                                        className="mt-0.5 rounded border-gray-300"
                                    />
                                    <span className="text-sm text-gray-700 min-w-0">
                                        <span className="font-medium">{risk.risk_code}</span>
                                        <span className="block text-xs text-gray-500 truncate">{risk.title}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    <InputError message={errors.risk_ids} className="mt-2" />
                </div>
            )}

            {(schema?.sections?.length ?? 0) > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-lg font-semibold text-[#1A365D] mb-6">Additional fields</h2>
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
