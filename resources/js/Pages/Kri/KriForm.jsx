import DynamicForm from '@thirdline/ui/Components/DynamicForm';
import InputError from '@thirdline/ui/Components/InputError';

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

/** Plain English for the bands the two numbers describe. */
function BandPreview({ direction, green, red, unit }) {
    if (green === '' || green === null || red === '' || red === null) {
        return <p className="text-xs text-gray-400">Enter both boundaries to see the bands.</p>;
    }

    const g = Number(green);
    const r = Number(red);
    const u = unit || '';

    const bands = direction === 'higher_is_worse'
        ? [
            { label: 'Green', tone: 'bg-green-100 text-green-800', text: `up to ${g}${u}` },
            { label: 'Amber', tone: 'bg-yellow-100 text-yellow-800', text: `over ${g}${u} and under ${r}${u}` },
            { label: 'Red', tone: 'bg-red-100 text-red-800', text: `${r}${u} and above` },
        ]
        : [
            { label: 'Green', tone: 'bg-green-100 text-green-800', text: `${g}${u} and above` },
            { label: 'Amber', tone: 'bg-yellow-100 text-yellow-800', text: `under ${g}${u} and over ${r}${u}` },
            { label: 'Red', tone: 'bg-red-100 text-red-800', text: `${r}${u} and below` },
        ];

    const inverted = direction === 'higher_is_worse' ? g > r : g < r;

    return (
        <div className="space-y-2">
            {bands.map((band) => (
                <div key={band.label} className="flex items-center gap-2 text-xs">
                    <span className={`badge ${band.tone} w-16 justify-center`}>{band.label}</span>
                    <span className="text-gray-600">{band.text}</span>
                </div>
            ))}
            {inverted && (
                <p className="text-xs text-amber-600">
                    The green and red boundaries look the wrong way round for this direction — amber would be empty.
                </p>
            )}
        </div>
    );
}

export default function KriForm({ data, setData, errors = {}, schema, risks = [], users = [], frequencies = [], directions = [], showRisk = false, showActive = false }) {
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
                            <input type="number" step="0.01" value={data.target_value ?? ''} onChange={(e) => setData('target_value', e.target.value)} className={INPUT} />
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
                        <BandPreview direction={data.direction} green={data.green_threshold} red={data.red_threshold} unit={data.measurement_unit} />
                    </div>
                </div>
            </Section>

            {(schema?.sections ?? []).length > 0 && (
                <Section index={3} title="Additional Fields">
                    <DynamicForm schema={schema} values={data} errors={errors} onChange={(code, value) => setData(code, value)} />
                </Section>
            )}
        </>
    );
}
