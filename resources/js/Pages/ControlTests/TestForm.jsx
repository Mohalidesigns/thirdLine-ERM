import InputError from '@/Components/InputError';

const INPUT =
    'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
const SELECT = `${INPUT} bg-white`;

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

/** The control-test form, shared by create and edit (migration Phase 3.4). */
export default function TestForm({ data, setData, errors, controls = [], users = [], options = {}, lockControl = false }) {
    const set = (field) => (e) => setData(field, e.target.value);

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="md:col-span-2">
                    <Field label="Control under test" required error={errors.control_id}>
                        <select
                            value={data.control_id ?? ''}
                            onChange={set('control_id')}
                            disabled={lockControl}
                            className={`${SELECT} ${lockControl ? 'bg-gray-100 text-gray-500' : ''}`}
                        >
                            <option value="">Select a control</option>
                            {controls.map((control) => (
                                <option key={control.id} value={control.id}>
                                    {control.control_code} — {control.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {lockControl && (
                        <p className="text-xs text-gray-500 mt-1">
                            The control a test was scheduled against cannot be changed; schedule a new test instead.
                        </p>
                    )}
                </div>

                <div className="md:col-span-2">
                    <Field label="Title" required error={errors.title}>
                        <input type="text" maxLength={255} value={data.title ?? ''} onChange={set('title')} className={INPUT} />
                    </Field>
                </div>

                <div className="md:col-span-2">
                    <Field label="Description" error={errors.description}>
                        <textarea rows={3} value={data.description ?? ''} onChange={set('description')} className={INPUT} />
                    </Field>
                </div>

                <Field label="Test type" required error={errors.test_type}>
                    <select value={data.test_type ?? ''} onChange={set('test_type')} className={SELECT}>
                        <option value="">Select type</option>
                        {(options.types ?? []).map((type) => (
                            <option key={type} value={type}>
                                {humanise(type)}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label="Scheduled date" required error={errors.scheduled_date}>
                    <input type="date" value={data.scheduled_date ?? ''} onChange={set('scheduled_date')} className={INPUT} />
                </Field>

                <Field label="Tester" required={!lockControl} error={errors.tester_id}>
                    <select value={data.tester_id ?? ''} onChange={set('tester_id')} className={SELECT}>
                        <option value="">Select tester</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field
                    label="Reviewer"
                    error={errors.reviewer_id}
                    hint="Optional. A test with no reviewer is complete on submission — there is nothing to approve."
                >
                    <select value={data.reviewer_id ?? ''} onChange={set('reviewer_id')} className={SELECT}>
                        <option value="">No reviewer</option>
                        {users.map((user) => (
                            <option key={user.id} value={user.id}>
                                {user.name}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
        </div>
    );
}
