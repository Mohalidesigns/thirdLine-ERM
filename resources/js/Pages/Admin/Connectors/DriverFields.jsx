import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';

/**
 * The fields a connector driver declares for itself (migration Phase 6.7).
 *
 * Rendered from `describe()`, which is why a new connector type gets a UI
 * without anybody writing one — the Blade screen did exactly this, and the
 * property is worth keeping.
 */
export default function DriverFields({ title, hint, fields, values, errors, prefix, onChange }) {
    const entries = Object.entries(fields ?? {});

    if (entries.length === 0) return null;

    return (
        <div>
            <p className="text-sm font-medium text-gray-700">{title}</p>
            {hint && <p className="text-xs text-gray-500 mb-2">{hint}</p>}

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-2">
                {entries.map(([key, field]) => {
                    const id = `${prefix}-${key}`;
                    const value = values?.[key] ?? '';
                    const error = errors?.[`${prefix}.${key}`];

                    return (
                        <div key={key} className={field.type === 'textarea' ? 'sm:col-span-2' : ''}>
                            <InputLabel htmlFor={id}>
                                <span className="text-xs font-medium text-gray-600">
                                    {field.label ?? key}
                                    {field.required && <span className="text-red-500"> *</span>}
                                </span>
                            </InputLabel>

                            {field.type === 'select' ? (
                                <select
                                    id={id}
                                    className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={value}
                                    onChange={(e) => onChange(key, e.target.value)}
                                >
                                    <option value="">—</option>
                                    {Object.entries(field.options ?? {}).map(([optionValue, label]) => (
                                        <option key={optionValue} value={optionValue}>
                                            {label}
                                        </option>
                                    ))}
                                </select>
                            ) : field.type === 'textarea' ? (
                                <textarea
                                    id={id}
                                    rows={3}
                                    className="mt-1 block w-full text-xs font-mono border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={value}
                                    onChange={(e) => onChange(key, e.target.value)}
                                />
                            ) : (
                                <TextInput
                                    id={id}
                                    type={
                                        field.type === 'password'
                                            ? 'password'
                                            : field.type === 'number'
                                              ? 'number'
                                              : 'text'
                                    }
                                    className="mt-1 block w-full text-sm"
                                    value={value}
                                    onChange={(e) => onChange(key, e.target.value)}
                                    autoComplete={field.type === 'password' ? 'new-password' : undefined}
                                />
                            )}

                            {field.help && <p className="text-xs text-gray-500 mt-1">{field.help}</p>}
                            <InputError message={error} className="mt-1" />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
