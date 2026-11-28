import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import InputError from '@thirdline/ui/Components/InputError';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const INPUT =
    'w-full px-3.5 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';
const SELECT = `${INPUT} bg-white`;

function Section({ number, title, subtitle, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div className="flex items-start gap-3 mb-5">
                <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold shrink-0">
                    {number}
                </div>
                <div>
                    <h2 className="text-base font-semibold text-[#1A365D]">{title}</h2>
                    {subtitle && <p className="text-xs text-gray-500 mt-0.5">{subtitle}</p>}
                </div>
            </div>
            {children}
        </div>
    );
}

function Field({ id, label, required = false, hint, error, children }) {
    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-700 mb-1.5">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-gray-500 mt-1">
                    {hint}
                </p>
            )}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * Shared Create & Edit form for Master Risk Library items (ADR 0028 Phase 1).
 *
 * Strictly definition-only: ZERO likelihood/impact scores, ZERO entity,
 * department, business unit, or owner fields (ISO 31000 §6.4.2).
 */
export default function LibraryForm({
    mode = 'create',
    item = null,
    options = {},
    form,
    onSubmit,
    cancelHref,
}) {
    const isEdit = mode === 'edit';
    const { data, setData, errors, processing } = form;
    const errorSummaryRef = useRef(null);

    // Live duplicate check state
    const [duplicates, setDuplicates] = useState([]);
    const [dupStatus, setDupStatus] = useState('idle'); // idle | loading | done | error

    const errorEntries = Object.entries(errors ?? {});

    // Focus validation summary on failed submit
    useEffect(() => {
        if (errorEntries.length > 0) {
            errorSummaryRef.current?.focus();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors]);

    // Debounced live duplicate check (400 ms, min 3 chars, AbortController)
    useEffect(() => {
        const title = (data.title ?? '').trim();
        if (title.length < 3) {
            setDuplicates([]);
            setDupStatus('idle');
            return;
        }

        const dupUrl = tryRoute('risk.library.duplicates');
        if (!dupUrl) return;

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setDupStatus('loading');
            try {
                const params = new URLSearchParams({ title });
                if (data.category_id) params.set('category_id', String(data.category_id));
                if (isEdit && item?.id) params.set('exclude_id', String(item.id));

                const res = await fetch(`${dupUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!res.ok) throw new Error('Duplicate check failed');
                const json = await res.json();
                setDuplicates(Array.isArray(json.matches) ? json.matches : []);
                setDupStatus('done');
            } catch (err) {
                if (err.name !== 'AbortError') {
                    setDupStatus('error');
                }
            }
        }, 400);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [data.title, data.category_id, isEdit, item?.id]);

    const selectedCategory = useMemo(
        () => (options.categories ?? []).find((c) => String(c.id) === String(data.category_id)),
        [options.categories, data.category_id],
    );

    const filteredBaselL2 = useMemo(() => {
        if (!data.basel_l1_event_type) return [];
        return (options.baselL2 ?? []).filter((b) => b.l1 === data.basel_l1_event_type);
    }, [options.baselL2, data.basel_l1_event_type]);

    // Repeater helpers
    const updateRepeater = (field, index, key, value) => {
        const next = [...(data[field] ?? [])];
        next[index] = { ...next[index], [key]: value };
        setData(field, next);
    };

    const addRepeaterRow = (field, template) => {
        setData(field, [...(data[field] ?? []), { ...template }]);
    };

    const removeRepeaterRow = (field, index) => {
        setData(
            field,
            (data[field] ?? []).filter((_, i) => i !== index),
        );
    };

    const nextVersion = isEdit && item?.current_version ? item.current_version + 1 : 1;

    return (
        <form onSubmit={onSubmit} noValidate>
            {errorEntries.length > 0 && (
                <div
                    ref={errorSummaryRef}
                    tabIndex={-1}
                    role="alert"
                    className="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 focus:outline-none focus:ring-2 focus:ring-red-400"
                >
                    <p className="font-semibold mb-1">Please fix the following errors before saving:</p>
                    <ul className="list-disc pl-5 space-y-0.5 text-xs">
                        {errorEntries.map(([field, message]) => (
                            <li key={field}>{message}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                {/* Main Form Column */}
                <div className="lg:col-span-2">
                    {/* Section 1: Master Risk Definition */}
                    <Section
                        number={1}
                        title="Master Risk Definition"
                        subtitle="Canonical statement shared across entities and processes."
                    >
                        <div className="mb-5 rounded-lg border border-blue-200 bg-blue-50/60 px-4 py-3 text-xs text-[#1A365D] flex items-start gap-2">
                            <span className="material-symbols-outlined text-base shrink-0 mt-0.5">info</span>
                            <span>
                                Master definitions contain no risk scores, owners, or departments. Those are
                                captured when applied to an entity or process.
                            </span>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div className="md:col-span-2">
                                <Field
                                    id="rl-title"
                                    label="Risk Title"
                                    required
                                    error={errors.title}
                                >
                                    <input
                                        id="rl-title"
                                        type="text"
                                        required
                                        maxLength={255}
                                        value={data.title ?? ''}
                                        onChange={(e) => setData('title', e.target.value)}
                                        placeholder="e.g. Payment Settlement Failure"
                                        className={INPUT}
                                    />
                                </Field>
                            </div>

                            <div>
                                <Field
                                    id="rl-code"
                                    label="Library Code"
                                    hint={
                                        isEdit
                                            ? 'Library codes are immutable after creation.'
                                            : 'Leave blank to auto-generate (e.g. RL-0001).'
                                    }
                                    error={errors.code}
                                >
                                    {isEdit ? (
                                        <div className="px-3.5 py-2 border border-gray-200 rounded-lg bg-gray-50 font-mono text-sm font-semibold text-[#1A365D]">
                                            {item?.code}
                                        </div>
                                    ) : (
                                        <input
                                            id="rl-code"
                                            type="text"
                                            maxLength={32}
                                            value={data.code ?? ''}
                                            onChange={(e) => setData('code', e.target.value)}
                                            placeholder="Auto-generated (e.g. RL-0001)"
                                            className={`${INPUT} font-mono`}
                                        />
                                    )}
                                </Field>
                            </div>

                            <div>
                                <Field
                                    id="rl-category"
                                    label="Risk Category"
                                    required
                                    error={errors.category_id}
                                >
                                    <select
                                        id="rl-category"
                                        required
                                        value={data.category_id ?? ''}
                                        onChange={(e) => setData('category_id', e.target.value)}
                                        className={SELECT}
                                    >
                                        <option value="">Select Category…</option>
                                        {(options.categories ?? []).map((cat) => (
                                            <option key={cat.id} value={cat.id}>
                                                {cat.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>

                            <div className="md:col-span-2">
                                <Field
                                    id="rl-description"
                                    label="Risk Description"
                                    required
                                    hint="Plain text only — keeps full-text search and version diffs clean."
                                    error={errors.description}
                                >
                                    <textarea
                                        id="rl-description"
                                        rows={4}
                                        required
                                        value={data.description ?? ''}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Describe the risk event, scope, and failure mode…"
                                        className={INPUT}
                                    />
                                </Field>
                            </div>

                            <div>
                                <Field
                                    id="rl-risk-source"
                                    label="Risk Source"
                                    error={errors.risk_source}
                                >
                                    <select
                                        id="rl-risk-source"
                                        value={data.risk_source ?? ''}
                                        onChange={(e) => setData('risk_source', e.target.value)}
                                        className={SELECT}
                                    >
                                        <option value="">Select Risk Source (optional)…</option>
                                        {(options.riskSources ?? []).map((src) => (
                                            <option key={src.value} value={src.value}>
                                                {src.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>

                            <div className="hidden md:block" />

                            <div>
                                <Field
                                    id="rl-basel-l1"
                                    label="Basel II/III Level 1 Event Type"
                                    hint={
                                        selectedCategory?.is_operational
                                            ? 'Recommended for Operational Risk categories (Basel OPE25).'
                                            : 'Optional — primarily used for Operational Risk definitions.'
                                    }
                                    error={errors.basel_l1_event_type}
                                >
                                    <select
                                        id="rl-basel-l1"
                                        value={data.basel_l1_event_type ?? ''}
                                        onChange={(e) => {
                                            setData((prev) => ({
                                                ...prev,
                                                basel_l1_event_type: e.target.value,
                                                basel_l2_event_type: '',
                                            }));
                                        }}
                                        className={SELECT}
                                    >
                                        <option value="">None / Not applicable</option>
                                        {(options.baselL1 ?? []).map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>

                            <div>
                                <Field
                                    id="rl-basel-l2"
                                    label="Basel II/III Level 2 Event Type"
                                    error={errors.basel_l2_event_type}
                                >
                                    <select
                                        id="rl-basel-l2"
                                        disabled={!data.basel_l1_event_type}
                                        value={data.basel_l2_event_type ?? ''}
                                        onChange={(e) => setData('basel_l2_event_type', e.target.value)}
                                        className={`${SELECT} disabled:bg-gray-100 disabled:text-gray-400`}
                                    >
                                        <option value="">
                                            {data.basel_l1_event_type
                                                ? 'Select Basel L2 (optional)…'
                                                : 'Select Basel L1 first'}
                                        </option>
                                        {filteredBaselL2.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                        </div>
                    </Section>

                    {/* Section 2: Typical Causes & Consequences */}
                    <Section
                        number={2}
                        title="Typical Causes & Consequences"
                        subtitle="ISO 31000 §6.4.2 cause-event-consequence taxonomy copied into new risk instances."
                    >
                        {/* Typical Causes */}
                        <div className="mb-6">
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-sm font-semibold text-gray-800">Typical Causes</h3>
                                <button
                                    type="button"
                                    onClick={() =>
                                        addRepeaterRow('typical_causes', {
                                            cause: '',
                                            cause_category_id: '',
                                        })
                                    }
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">add</span>
                                    Add Cause
                                </button>
                            </div>

                            {(data.typical_causes ?? []).length === 0 ? (
                                <p className="text-xs text-gray-500 italic py-2">
                                    No typical causes added yet.
                                </p>
                            ) : (
                                <div className="space-y-2.5">
                                    {(data.typical_causes ?? []).map((row, idx) => (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start p-3 rounded-lg border border-gray-200 bg-gray-50/50"
                                        >
                                            <div className="sm:col-span-7">
                                                <label
                                                    htmlFor={`cause-text-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Cause {idx + 1}
                                                </label>
                                                <input
                                                    id={`cause-text-${idx}`}
                                                    type="text"
                                                    maxLength={500}
                                                    value={row.cause ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater('typical_causes', idx, 'cause', e.target.value)
                                                    }
                                                    placeholder="e.g. Manual reconciliation error"
                                                    className={INPUT}
                                                />
                                                <InputError
                                                    message={errors[`typical_causes.${idx}.cause`]}
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div className="sm:col-span-4">
                                                <label
                                                    htmlFor={`cause-cat-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Cause category {idx + 1}
                                                </label>
                                                <select
                                                    id={`cause-cat-${idx}`}
                                                    value={row.cause_category_id ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'typical_causes',
                                                            idx,
                                                            'cause_category_id',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className={SELECT}
                                                >
                                                    <option value="">Cause Category (opt.)</option>
                                                    {(options.causeCategories ?? []).map((c) => (
                                                        <option key={c.id} value={c.id}>
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-1 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={() => removeRepeaterRow('typical_causes', idx)}
                                                    aria-label={`Remove cause ${idx + 1}`}
                                                    className="p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50"
                                                >
                                                    <span className="material-symbols-outlined text-lg">delete</span>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Typical Consequences */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-sm font-semibold text-gray-800">Typical Consequences</h3>
                                <button
                                    type="button"
                                    onClick={() =>
                                        addRepeaterRow('typical_consequences', {
                                            consequence: '',
                                            impact_domain: '',
                                        })
                                    }
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">add</span>
                                    Add Consequence
                                </button>
                            </div>

                            {(data.typical_consequences ?? []).length === 0 ? (
                                <p className="text-xs text-gray-500 italic py-2">
                                    No typical consequences added yet.
                                </p>
                            ) : (
                                <div className="space-y-2.5">
                                    {(data.typical_consequences ?? []).map((row, idx) => (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start p-3 rounded-lg border border-gray-200 bg-gray-50/50"
                                        >
                                            <div className="sm:col-span-7">
                                                <label
                                                    htmlFor={`consequence-text-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Consequence {idx + 1}
                                                </label>
                                                <input
                                                    id={`consequence-text-${idx}`}
                                                    type="text"
                                                    maxLength={500}
                                                    value={row.consequence ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'typical_consequences',
                                                            idx,
                                                            'consequence',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Regulatory sanction or customer compensation"
                                                    className={INPUT}
                                                />
                                                <InputError
                                                    message={errors[`typical_consequences.${idx}.consequence`]}
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div className="sm:col-span-4">
                                                <label
                                                    htmlFor={`consequence-domain-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Impact domain {idx + 1}
                                                </label>
                                                <select
                                                    id={`consequence-domain-${idx}`}
                                                    value={row.impact_domain ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'typical_consequences',
                                                            idx,
                                                            'impact_domain',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className={SELECT}
                                                >
                                                    <option value="">Impact Domain (opt.)</option>
                                                    {(options.impactDomains ?? []).map((domain) => (
                                                        <option key={domain} value={domain}>
                                                            {domain}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-1 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={() => removeRepeaterRow('typical_consequences', idx)}
                                                    aria-label={`Remove consequence ${idx + 1}`}
                                                    className="p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50"
                                                >
                                                    <span className="material-symbols-outlined text-lg">delete</span>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </Section>

                    {/* Section 3: Reference Controls & KRIs */}
                    <Section
                        number={3}
                        title="Reference Controls & KRIs"
                        subtitle="Reference prompts only — instantiating this risk will not auto-create controls or KRIs."
                    >
                        {/* Suggested Controls */}
                        <div className="mb-6">
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-sm font-semibold text-gray-800">Suggested Controls</h3>
                                <button
                                    type="button"
                                    onClick={() =>
                                        addRepeaterRow('suggested_controls', {
                                            title: '',
                                            control_id: '',
                                            control_type: '',
                                        })
                                    }
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">add</span>
                                    Add Control
                                </button>
                            </div>

                            {(data.suggested_controls ?? []).length === 0 ? (
                                <p className="text-xs text-gray-500 italic py-2">
                                    No suggested controls listed yet.
                                </p>
                            ) : (
                                <div className="space-y-2.5">
                                    {(data.suggested_controls ?? []).map((row, idx) => (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start p-3 rounded-lg border border-gray-200 bg-gray-50/50"
                                        >
                                            <div className="sm:col-span-5">
                                                <label
                                                    htmlFor={`ctrl-title-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Suggested control title {idx + 1}
                                                </label>
                                                <input
                                                    id={`ctrl-title-${idx}`}
                                                    type="text"
                                                    maxLength={255}
                                                    value={row.title ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'suggested_controls',
                                                            idx,
                                                            'title',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Control title or prompt"
                                                    className={INPUT}
                                                />
                                                <InputError
                                                    message={errors[`suggested_controls.${idx}.title`]}
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div className="sm:col-span-4">
                                                <label
                                                    htmlFor={`ctrl-link-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Link existing control {idx + 1}
                                                </label>
                                                <select
                                                    id={`ctrl-link-${idx}`}
                                                    value={row.control_id ?? ''}
                                                    onChange={(e) => {
                                                        const chosenId = e.target.value;
                                                        const matched = (options.existingControls ?? []).find(
                                                            (c) => String(c.id) === chosenId,
                                                        );
                                                        const next = [...(data.suggested_controls ?? [])];
                                                        next[idx] = {
                                                            ...next[idx],
                                                            control_id: chosenId,
                                                            title: next[idx].title || matched?.title || '',
                                                        };
                                                        setData('suggested_controls', next);
                                                    }}
                                                    className={SELECT}
                                                >
                                                    <option value="">Link existing control (opt.)</option>
                                                    {(options.existingControls ?? []).map((c) => (
                                                        <option key={c.id} value={c.id}>
                                                            {c.code} — {c.title}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <label
                                                    htmlFor={`ctrl-type-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Control type {idx + 1}
                                                </label>
                                                <select
                                                    id={`ctrl-type-${idx}`}
                                                    value={row.control_type ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'suggested_controls',
                                                            idx,
                                                            'control_type',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className={SELECT}
                                                >
                                                    <option value="">Type</option>
                                                    {(options.controlTypes ?? []).map((t) => (
                                                        <option key={t} value={t}>
                                                            {t.charAt(0).toUpperCase() + t.slice(1)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-1 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={() => removeRepeaterRow('suggested_controls', idx)}
                                                    aria-label={`Remove control ${idx + 1}`}
                                                    className="p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50"
                                                >
                                                    <span className="material-symbols-outlined text-lg">delete</span>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Suggested KRIs */}
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <h3 className="text-sm font-semibold text-gray-800">Suggested KRIs</h3>
                                <button
                                    type="button"
                                    onClick={() =>
                                        addRepeaterRow('suggested_kris', {
                                            name: '',
                                            kri_id: '',
                                            metric_unit: '',
                                        })
                                    }
                                    className="btn-secondary text-xs inline-flex items-center gap-1"
                                >
                                    <span className="material-symbols-outlined text-sm">add</span>
                                    Add KRI
                                </button>
                            </div>

                            {(data.suggested_kris ?? []).length === 0 ? (
                                <p className="text-xs text-gray-500 italic py-2">
                                    No suggested KRIs listed yet.
                                </p>
                            ) : (
                                <div className="space-y-2.5">
                                    {(data.suggested_kris ?? []).map((row, idx) => (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start p-3 rounded-lg border border-gray-200 bg-gray-50/50"
                                        >
                                            <div className="sm:col-span-5">
                                                <label
                                                    htmlFor={`kri-name-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Suggested KRI name {idx + 1}
                                                </label>
                                                <input
                                                    id={`kri-name-${idx}`}
                                                    type="text"
                                                    maxLength={255}
                                                    value={row.name ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater('suggested_kris', idx, 'name', e.target.value)
                                                    }
                                                    placeholder="e.g. Failed settlement count"
                                                    className={INPUT}
                                                />
                                                <InputError
                                                    message={errors[`suggested_kris.${idx}.name`]}
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div className="sm:col-span-4">
                                                <label
                                                    htmlFor={`kri-link-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Link existing KRI {idx + 1}
                                                </label>
                                                <select
                                                    id={`kri-link-${idx}`}
                                                    value={row.kri_id ?? ''}
                                                    onChange={(e) => {
                                                        const chosenId = e.target.value;
                                                        const matched = (options.existingKris ?? []).find(
                                                            (k) => String(k.id) === chosenId,
                                                        );
                                                        const next = [...(data.suggested_kris ?? [])];
                                                        next[idx] = {
                                                            ...next[idx],
                                                            kri_id: chosenId,
                                                            name: next[idx].name || matched?.name || '',
                                                        };
                                                        setData('suggested_kris', next);
                                                    }}
                                                    className={SELECT}
                                                >
                                                    <option value="">Link existing KRI (opt.)</option>
                                                    {(options.existingKris ?? []).map((k) => (
                                                        <option key={k.id} value={k.id}>
                                                            {k.code} — {k.name}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="sm:col-span-2">
                                                <label
                                                    htmlFor={`kri-unit-${idx}`}
                                                    className="sr-only"
                                                >
                                                    Metric unit {idx + 1}
                                                </label>
                                                <input
                                                    id={`kri-unit-${idx}`}
                                                    type="text"
                                                    maxLength={64}
                                                    value={row.metric_unit ?? ''}
                                                    onChange={(e) =>
                                                        updateRepeater(
                                                            'suggested_kris',
                                                            idx,
                                                            'metric_unit',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Unit (e.g. %)"
                                                    className={INPUT}
                                                />
                                            </div>
                                            <div className="sm:col-span-1 flex justify-end">
                                                <button
                                                    type="button"
                                                    onClick={() => removeRepeaterRow('suggested_kris', idx)}
                                                    aria-label={`Remove KRI ${idx + 1}`}
                                                    className="p-2 text-gray-400 hover:text-red-600 rounded-lg hover:bg-red-50"
                                                >
                                                    <span className="material-symbols-outlined text-lg">delete</span>
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </Section>

                    {/* Section 4: Version Change Summary (Edit mode only) */}
                    {isEdit && (
                        <Section
                            number={4}
                            title="Version Change Summary"
                            subtitle={`Required when publishing v${nextVersion}.`}
                        >
                            <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                                Saving creates version <strong>v{nextVersion}</strong>. Existing risk instances
                                will keep <strong>v{item?.current_version}</strong> and display a{' '}
                                &ldquo;Library updated — review changes&rdquo; banner for risk owners.
                            </div>

                            <Field
                                id="rl-change-summary"
                                label="Change Summary"
                                required
                                hint={`Explain what changed for risk owners reviewing v${item?.current_version} → v${nextVersion} (min 5 characters).`}
                                error={errors.change_summary}
                            >
                                <textarea
                                    id="rl-change-summary"
                                    rows={3}
                                    required
                                    minLength={5}
                                    maxLength={2000}
                                    value={data.change_summary ?? ''}
                                    onChange={(e) => setData('change_summary', e.target.value)}
                                    placeholder="e.g. Added instant payment clearing failure causes and updated Basel L2 classification."
                                    className={INPUT}
                                />
                            </Field>
                        </Section>
                    )}

                    {/* Action Footer */}
                    <div className="bg-white rounded-xl border border-gray-200 px-6 py-4 flex items-center justify-between gap-3 sticky bottom-4 shadow-sm">
                        <Link href={cancelHref} className="btn-secondary text-sm">
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50"
                        >
                            <span className="material-symbols-outlined text-lg">
                                {isEdit ? 'publish' : 'add_circle'}
                            </span>
                            {processing
                                ? isEdit
                                    ? `Publishing v${nextVersion}…`
                                    : 'Saving…'
                                : isEdit
                                  ? `Publish Version v${nextVersion}`
                                  : 'Create Library Risk'}
                        </button>
                    </div>
                </div>

                {/* Right Sticky Sidebar: Live Duplicate Prevention Panel */}
                <aside className="lg:col-span-1">
                    <div className="bg-white rounded-xl border border-gray-200 p-5 sticky top-6">
                        <div className="flex items-center gap-2 mb-3">
                            <span className="material-symbols-outlined text-[#1A365D] text-xl">
                                manage_search
                            </span>
                            <h3 className="text-sm font-semibold text-[#1A365D]">
                                Similar Existing Risks
                            </h3>
                        </div>

                        <div aria-live="polite">
                            {dupStatus === 'idle' && (
                                <p className="text-xs text-gray-500">
                                    Type a title (at least 3 characters) to check for similar definitions
                                    already in the Risk Library.
                                </p>
                            )}

                            {dupStatus === 'loading' && (
                                <p className="text-xs text-gray-500">Checking library…</p>
                            )}

                            {dupStatus === 'error' && (
                                <p className="text-xs text-gray-500">
                                    Duplicate check unavailable right now — you can still save.
                                </p>
                            )}

                            {dupStatus === 'done' && duplicates.length === 0 && (
                                <div className="flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 p-3 text-xs text-[#2D7D46]">
                                    <span className="material-symbols-outlined text-base shrink-0">
                                        check_circle
                                    </span>
                                    <span>No close matches found in the library.</span>
                                </div>
                            )}

                            {dupStatus === 'done' && duplicates.length > 0 && (
                                <div className="space-y-3">
                                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                                        <strong>
                                            {duplicates.length} similar library{' '}
                                            {duplicates.length === 1 ? 'risk' : 'risks'} found.
                                        </strong>{' '}
                                        Review these before saving to avoid duplicate master risks.
                                    </div>

                                    <div className="space-y-2">
                                        {duplicates.map((match) => (
                                            <div
                                                key={match.id}
                                                className="rounded-lg border border-gray-200 p-3 hover:border-[#1A365D]/40 transition-colors"
                                            >
                                                <div className="flex items-center justify-between gap-2 mb-1">
                                                    <span className="font-mono text-xs font-semibold text-[#1A365D]">
                                                        {match.code}
                                                    </span>
                                                    <StatusBadge status={match.status} />
                                                </div>
                                                <p className="text-xs font-medium text-gray-900 leading-snug">
                                                    {match.title}
                                                </p>
                                                <div className="flex items-center justify-between gap-2 mt-2 text-[11px] text-gray-500">
                                                    <span>
                                                        {match.category_name ?? '—'} · v{match.current_version}
                                                    </span>
                                                    <a
                                                        href={match.show_url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="font-medium text-[#1A365D] hover:underline inline-flex items-center gap-0.5"
                                                    >
                                                        View
                                                        <span className="material-symbols-outlined text-xs">
                                                            open_in_new
                                                        </span>
                                                    </a>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    );
}

/**
 * Clean repeater arrays before POST/PUT so blank optional rows do not fail
 * server validation on nested required keys.
 */
export function cleanLibraryPayload(data, { includeCode = true, includeChangeSummary = false } = {}) {
    const payload = {
        title: data.title ?? '',
        description: data.description ?? '',
        category_id: data.category_id ? Number(data.category_id) : '',
        risk_source: data.risk_source || null,
        basel_l1_event_type: data.basel_l1_event_type || null,
        basel_l2_event_type: data.basel_l1_event_type && data.basel_l2_event_type ? data.basel_l2_event_type : null,
        typical_causes: (data.typical_causes ?? [])
            .filter((r) => (r.cause ?? '').trim() !== '')
            .map((r) => ({
                cause: r.cause.trim(),
                cause_category_id: r.cause_category_id ? Number(r.cause_category_id) : null,
            })),
        typical_consequences: (data.typical_consequences ?? [])
            .filter((r) => (r.consequence ?? '').trim() !== '')
            .map((r) => ({
                consequence: r.consequence.trim(),
                impact_domain: r.impact_domain || null,
            })),
        suggested_controls: (data.suggested_controls ?? [])
            .filter((r) => (r.title ?? '').trim() !== '')
            .map((r) => ({
                title: r.title.trim(),
                control_id: r.control_id ? Number(r.control_id) : null,
                control_type: r.control_type || null,
            })),
        suggested_kris: (data.suggested_kris ?? [])
            .filter((r) => (r.name ?? '').trim() !== '')
            .map((r) => ({
                name: r.name.trim(),
                kri_id: r.kri_id ? Number(r.kri_id) : null,
                metric_unit: (r.metric_unit ?? '').trim() || null,
            })),
    };

    if (includeCode) {
        payload.code = (data.code ?? '').trim() || null;
    }

    if (includeChangeSummary) {
        payload.change_summary = (data.change_summary ?? '').trim();
    }

    return payload;
}
