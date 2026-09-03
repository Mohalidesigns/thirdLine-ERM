import Checkbox from './Checkbox';
import InputError from './InputError';
import InputLabel from './InputLabel';

/**
 * Renders the fields of an object type from the schema
 * App\Presenters\FormSchemaPresenter::form() produces (migration Phase 2, 2.4).
 *
 * The React counterpart of <x-dynamic-form>. It renders fields, not a form:
 * the enclosing <form>, its submit and its useForm() belong to the caller,
 * which is what lets a page keep a bespoke section beside the generated ones.
 *
 *     const { data, setData, post, errors } = useForm(initialValues(schema));
 *     <DynamicForm schema={schema} values={data} onChange={setData} errors={errors} />
 *     post(route('risk.controls.store'), { data: formDataFor(schema, data) })
 *
 * Which fields exist and which the user may see was decided on the server;
 * a role-gated field is not in the schema at all. `visibleWhen` is a display
 * rule evaluated here, and only here — what a user may SET is decided again
 * on submit by PersistsConfiguredAttributes.
 */

const INPUT =
    'form-input mt-1 w-full rounded-lg border px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-1 focus:ring-[#1A365D]';
const SELECT = INPUT.replace('form-input', 'form-select') + ' bg-white';

/** Every field in the schema, in render order, regardless of section. */
export function fieldsOf(schema) {
    return (schema?.sections ?? []).flatMap((section) => section.fields ?? []);
}

/** `{ code: value }` from the schema's current values, for useForm(). */
export function initialValues(schema) {
    const values = {};

    for (const field of fieldsOf(schema)) {
        values[field.code] = initialValue(field);
    }

    return values;
}

/** The HTML/post name of a field: its column, or configured_attributes[code]. */
export function postName(field) {
    return field.name;
}

/**
 * Values keyed the way the server reads them: a mapped field under its
 * column name, an unmapped one under configured_attributes.code. Read-only
 * (formula) fields are never posted.
 */
export function formDataFor(schema, values) {
    const data = {};
    const configured = {};

    for (const field of fieldsOf(schema)) {
        if (field.readonly) continue;

        const value = outgoingValue(field, values?.[field.code]);

        if (field.mapped) {
            data[field.name] = value;
        } else {
            configured[field.code] = value;
        }
    }

    if (Object.keys(configured).length > 0) {
        data.configured_attributes = configured;
    }

    return data;
}

/** Whether a field's display condition holds against the current values. */
export function isVisible(field, values) {
    const rule = field.visibleWhen;

    if (!rule || !rule.field) return true;

    const actual = values?.[rule.field];

    if ('equals' in rule) return looselyEqual(actual, rule.equals);
    if ('not_equals' in rule) return !looselyEqual(actual, rule.not_equals);
    if ('in' in rule) return (rule.in ?? []).some((candidate) => looselyEqual(actual, candidate));
    if ('filled' in rule) {
        const filled = actual !== null && actual !== undefined && actual !== '' && actual !== false;

        return rule.filled ? filled : !filled;
    }

    return true;
}

export default function DynamicForm({ schema, values = {}, onChange, errors = {}, sections = null }) {
    const visibleSections = (schema?.sections ?? []).filter(
        (section) => sections === null || sections.includes(section.code),
    );

    if (visibleSections.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center">
                <span className="material-symbols-outlined text-3xl text-gray-400">tune</span>
                <p className="mt-2 text-sm text-gray-500">
                    No fields are configured on {schema?.objectType?.name ?? 'this type'} yet.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-8">
            {visibleSections.map((section) => (
                <section key={section.code}>
                    {visibleSections.length > 1 && (
                        <h3 className="mb-4 border-b border-gray-100 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {section.label}
                        </h3>
                    )}

                    <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                        {section.fields.map((field) =>
                            isVisible(field, values) ? (
                                <Field
                                    key={field.code}
                                    field={field}
                                    value={values[field.code]}
                                    error={errors[field.errorKey]}
                                    onChange={(value) => onChange?.(field.code, value)}
                                />
                            ) : null,
                        )}
                    </div>
                </section>
            ))}
        </div>
    );
}

function Field({ field, value, error, onChange }) {
    const id = `field-${field.code}`;
    const inputClass = INPUT + (error ? ' border-red-400' : ' border-gray-200');
    const selectClass = SELECT + (error ? ' border-red-400' : ' border-gray-200');

    return (
        <div className={field.width === 'full' ? 'md:col-span-2' : ''}>
            <InputLabel htmlFor={id} className="text-xs">
                {field.label}
                {field.required && <span className="text-red-500"> *</span>}
                {field.pii && <PiiChip />}
            </InputLabel>

            <Input field={field} id={id} value={value} onChange={onChange} inputClass={inputClass} selectClass={selectClass} />

            {field.help && <p className="mt-1 text-[11px] text-gray-400">{field.help}</p>}

            <InputError message={error} className="mt-1 text-xs" />
        </div>
    );
}

function Input({ field, id, value, onChange, inputClass, selectClass }) {
    if (field.readonly) {
        return (
            <div className="mt-1 rounded-lg bg-gray-50 px-3 py-2 font-mono text-xs text-gray-500" title={field.formula ?? undefined}>
                {value ?? field.formula ?? '—'}
            </div>
        );
    }

    if (field.type === 'multi_enum') {
        const selected = Array.isArray(value) ? value : value == null || value === '' ? [] : [value];

        return (
            <div className="mt-1 flex flex-wrap gap-3 rounded-lg border border-gray-200 p-3">
                {(field.options ?? []).map((option) => {
                    const checked = selected.some((item) => looselyEqual(item, option.value));

                    return (
                        <label key={String(option.value)} className="inline-flex items-center gap-2 text-sm text-gray-700">
                            <Checkbox
                                checked={checked}
                                onChange={(e) =>
                                    onChange(
                                        e.target.checked
                                            ? [...selected, option.value]
                                            : selected.filter((item) => !looselyEqual(item, option.value)),
                                    )
                                }
                            />
                            <span>{option.label}</span>
                        </label>
                    );
                })}
                {(field.options ?? []).length === 0 && (
                    <span className="text-[11px] text-amber-600">Nothing to choose from yet.</span>
                )}
            </div>
        );
    }

    if (field.options !== null && field.options !== undefined) {
        return (
            <>
                <select
                    id={id}
                    className={selectClass}
                    required={field.required}
                    value={value == null ? '' : String(value)}
                    onChange={(e) => onChange(optionValue(field, e.target.value))}
                >
                    <option value="">{field.required ? 'Choose…' : '— none —'}</option>
                    {field.options.map((option) => (
                        <option key={String(option.value)} value={String(option.value)}>
                            {option.label}
                        </option>
                    ))}
                </select>
                {field.options.length === 0 && (
                    <p className="mt-1 text-[11px] text-amber-600">Nothing to choose from yet — create one first.</p>
                )}
            </>
        );
    }

    switch (field.type) {
        case 'text':
            return (
                <textarea
                    id={id}
                    rows={3}
                    className={inputClass}
                    required={field.required}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'json':
            return (
                <textarea
                    id={id}
                    rows={4}
                    className={inputClass + ' font-mono'}
                    value={typeof value === 'string' ? value : value == null ? '' : JSON.stringify(value, null, 2)}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'bool':
            return (
                <div className="mt-2">
                    <label className="inline-flex items-center gap-2 text-sm text-gray-700">
                        <Checkbox id={id} checked={truthy(value)} onChange={(e) => onChange(e.target.checked)} />
                        Yes
                    </label>
                </div>
            );

        case 'money':
            return (
                <div className="relative">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">₦</span>
                    <input
                        type="number"
                        step="0.01"
                        id={id}
                        className={inputClass + ' pl-8'}
                        required={field.required}
                        value={value ?? ''}
                        onChange={(e) => onChange(e.target.value)}
                    />
                </div>
            );

        case 'int':
        case 'decimal':
        case 'user':
        case 'object_ref':
            return (
                <input
                    type="number"
                    step={field.type === 'decimal' ? '0.01' : '1'}
                    id={id}
                    className={inputClass}
                    required={field.required}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        case 'date':
        case 'datetime':
            return (
                <input
                    type={field.type === 'date' ? 'date' : 'datetime-local'}
                    id={id}
                    className={inputClass}
                    required={field.required}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );

        default:
            return (
                <input
                    type="text"
                    id={id}
                    className={inputClass}
                    required={field.required}
                    value={value ?? ''}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}

function PiiChip() {
    return (
        <span
            className="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[9px] font-medium text-amber-700"
            title="Personal data — never written to a log line"
        >
            PII
        </span>
    );
}

/* ------------------------------------------------------------------ */

function initialValue(field) {
    const value = field.value;

    if (field.type === 'multi_enum') {
        return Array.isArray(value) ? value : value == null || value === '' ? [] : [value];
    }

    if (field.type === 'bool') {
        return truthy(value);
    }

    if (field.type === 'json' && value !== null && typeof value === 'object') {
        return JSON.stringify(value, null, 2);
    }

    return value ?? (field.type === 'bool' ? false : '');
}

function outgoingValue(field, value) {
    if (field.type === 'json' && typeof value === 'string') {
        const trimmed = value.trim();

        if (trimmed === '') return null;

        try {
            return JSON.parse(trimmed);
        } catch {
            // Let the server's `array` rule say what is wrong with it.
            return value;
        }
    }

    if (field.type === 'bool') {
        return truthy(value);
    }

    return value === undefined ? null : value;
}

/** The option whose stringified value matches, so an int lookup posts an int. */
function optionValue(field, raw) {
    if (raw === '') return null;

    const match = (field.options ?? []).find((option) => String(option.value) === raw);

    return match ? match.value : raw;
}

function looselyEqual(a, b) {
    if (a === b) return true;
    if (a == null || b == null) return false;
    if (typeof a === 'boolean' || typeof b === 'boolean') return truthy(a) === truthy(b);

    return String(a) === String(b);
}

function truthy(value) {
    return value === true || value === 1 || value === '1' || value === 'true' || value === 'on';
}
