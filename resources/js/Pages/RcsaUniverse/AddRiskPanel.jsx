import { useEffect, useMemo, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import InputError from '@thirdline/ui/Components/InputError';

/**
 * Add Risk — the three-step slide-over of plan §6.2.
 *
 * SAVE & ADD ANOTHER IS THE POINT OF THIS COMPONENT. A risk champion entering
 * sixty risks for a department should never touch the mouse: the placement
 * (business unit, process, sub-process) stays put between saves, only the risk
 * and its controls are cleared, and focus returns to Potential Risk. Everything
 * else here exists to make that loop fast — Ctrl+Enter saves and continues,
 * Escape closes, and every control in the panel is reachable by Tab in the
 * order it is read.
 *
 * It is a purpose-built panel rather than the shared Modal component: Modal is
 * a centred dialog, this is an edge-anchored slide-over that must not trap the
 * page behind an opaque backdrop while a user checks a risk they just entered
 * — and Modal is flagged in the migration notes as an unverified component.
 */

const EMPTY_CONTROL = {
    description: '',
    control_type: '',
    frequency: '',
    control_owner_id: '',
    is_key: false,
};

const STEPS = [
    { key: 'placement', label: 'Placement' },
    { key: 'risk', label: 'Risk' },
    { key: 'controls', label: 'Controls' },
];

export default function AddRiskPanel({ open, onClose, options, editing = null }) {
    const isEditing = editing !== null;
    const [step, setStep] = useState('placement');
    const potentialRiskRef = useRef(null);
    const [justSaved, setJustSaved] = useState(null);

    const form = useForm(blankRisk());

    function blankRisk() {
        return {
            business_unit_id: '',
            process_id: '',
            sub_process_id: '',
            system_ids: [],
            risk_no: '',
            potential_risk: '',
            risk_driver: '',
            risk_category: '',
            secondary_categories: [],
            default_likelihood: '',
            default_impact: '',
            owner_id: '',
            controls: [{ ...EMPTY_CONTROL }],
        };
    }

    // Load the row being edited, or reset to a blank one when the panel opens
    // for a create. Without the reset, closing a half-filled panel and
    // reopening it shows the abandoned draft.
    useEffect(() => {
        if (!open) return;

        if (isEditing) {
            form.setData({
                business_unit_id: editing.business_unit_id ?? '',
                process_id: editing.process_id ?? '',
                sub_process_id: editing.sub_process_id ?? '',
                system_ids: editing.system_ids ?? [],
                risk_no: editing.risk_no ?? '',
                potential_risk: editing.potential_risk ?? '',
                risk_driver: editing.risk_driver ?? '',
                risk_category: editing.risk_category ?? '',
                secondary_categories: editing.secondary_categories ?? [],
                default_likelihood: editing.default_likelihood ?? '',
                default_impact: editing.default_impact ?? '',
                owner_id: editing.owner_id ?? '',
                controls: (editing.controls ?? []).length
                    ? editing.controls.map((c) => ({
                          id: c.id,
                          description: c.description ?? '',
                          control_type: c.control_type ?? '',
                          frequency: c.frequency ?? '',
                          control_owner_id: c.control_owner_id ?? '',
                          is_key: !!c.is_key,
                      }))
                    : [{ ...EMPTY_CONTROL }],
            });
            setStep('placement');
        } else {
            form.setData(blankRisk());
            setStep('placement');
        }
        setJustSaved(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editing?.id]);

    /* ------------------------------------------------------------------ */
    /*  Cascading selects                                                  */
    /* ------------------------------------------------------------------ */

    const processes = useMemo(
        () =>
            (options.processes ?? []).filter(
                (p) =>
                    p.parent_id === null &&
                    (!form.data.business_unit_id ||
                        String(p.business_unit_id) === String(form.data.business_unit_id)),
            ),
        [options.processes, form.data.business_unit_id],
    );

    const subProcesses = useMemo(
        () =>
            (options.processes ?? []).filter(
                (p) => form.data.process_id && String(p.parent_id) === String(form.data.process_id),
            ),
        [options.processes, form.data.process_id],
    );

    /* ------------------------------------------------------------------ */
    /*  Controls repeater                                                  */
    /* ------------------------------------------------------------------ */

    const setControl = (index, key, value) => {
        const next = [...form.data.controls];
        next[index] = { ...next[index], [key]: value };
        form.setData('controls', next);
    };

    const addControl = () => form.setData('controls', [...form.data.controls, { ...EMPTY_CONTROL }]);

    const removeControl = (index) =>
        form.setData(
            'controls',
            form.data.controls.filter((_, i) => i !== index),
        );

    /* ------------------------------------------------------------------ */
    /*  Inline process creation (§6.2)                                     */
    /* ------------------------------------------------------------------ */

    // `creating` is null, 'process' or 'sub_process' — which inline input is
    // open. NOT window.prompt: a modal prompt steals focus out of the panel,
    // cannot be styled or validated, is blocked outright in some embedded
    // browsers, and §6.2's requirement is precisely that the user does not
    // leave the form.
    const [creating, setCreating] = useState(null);
    const [newProcessName, setNewProcessName] = useState('');

    const openCreate = (which) => {
        setCreating(which);
        setNewProcessName('');
    };

    const createProcess = () => {
        const name = newProcessName.trim();

        if (name === '' || !form.data.business_unit_id) return;

        const parentId = creating === 'sub_process' ? form.data.process_id : null;

        router.post(
            route('rcsa.universe.processes.store'),
            { business_unit_id: form.data.business_unit_id, parent_id: parentId, name },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['options', 'flash', 'errors'],
                onSuccess: () => {
                    setCreating(null);
                    setNewProcessName('');
                },
            },
        );
    };

    const inlineCreate = (which) =>
        creating !== which ? null : (
            <div className="mt-2 flex gap-2">
                <input
                    autoFocus
                    type="text"
                    className="filter-input flex-1"
                    placeholder={which === 'process' ? 'New process name' : 'New sub-process name'}
                    value={newProcessName}
                    onChange={(e) => setNewProcessName(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            e.stopPropagation();
                            createProcess();
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            e.stopPropagation();
                            setCreating(null);
                        }
                    }}
                />
                <button type="button" onClick={createProcess} className="btn-primary text-sm">
                    Create
                </button>
                <button type="button" onClick={() => setCreating(null)} className="btn-secondary text-sm">
                    Cancel
                </button>
            </div>
        );

    /* ------------------------------------------------------------------ */
    /*  Saving                                                             */
    /* ------------------------------------------------------------------ */

    // The intent lives in a ref and is injected with transform(), never through
    // setData() before post(). setData is asynchronous, so a handler that sets
    // it and posts in the same tick sends the PREVIOUS value — the defect that
    // made a "return for rework" button approve a submission elsewhere in this
    // product. A ref is read synchronously.
    const continueAfterSave = useRef(false);

    // Which step each field lives on, so a validation failure can put the user
    // in front of the field that failed instead of leaving them on a step that
    // looks fine.
    const STEP_OF = {
        business_unit_id: 'placement',
        process_id: 'placement',
        sub_process_id: 'placement',
        system_ids: 'placement',
        risk_no: 'risk',
        potential_risk: 'risk',
        risk_driver: 'risk',
        risk_category: 'risk',
        secondary_categories: 'risk',
        owner_id: 'risk',
    };

    const stepOfError = (field) => (field.startsWith('controls') ? 'controls' : STEP_OF[field] ?? 'risk');

    const submit = (again) => {
        continueAfterSave.current = again;

        const options = {
            preserveScroll: true,
            // Jump to the step carrying the first failure. Without this the
            // panel can sit on Placement reporting nothing while the real
            // error is three fields down on another step — which is how a
            // form comes to "do nothing" when you press Save.
            onError: (errors) => {
                const first = Object.keys(errors)[0];

                if (first) setStep(stepOfError(first));
            },
            onSuccess: () => {
                if (continueAfterSave.current) {
                    // Placement is sticky; the risk and its controls are not.
                    form.setData((data) => ({
                        ...data,
                        risk_no: '',
                        potential_risk: '',
                        risk_driver: '',
                        risk_category: data.risk_category,
                        secondary_categories: [],
                        default_likelihood: '',
                        default_impact: '',
                        controls: [{ ...EMPTY_CONTROL }],
                    }));
                    setStep('risk');
                    setJustSaved(Date.now());
                    window.setTimeout(() => potentialRiskRef.current?.focus(), 0);
                } else {
                    onClose();
                }
            },
        };

        // DROP THE BLANK CONTROL ROW BEFORE SENDING. The repeater always shows
        // one empty row so there is something to type into, and
        // `controls.*.description` is required — so an untouched row made
        // every save fail with an error the user could only see by switching
        // to the Controls step. A risk with no controls is a legitimate
        // universe row; an empty repeater slot is not a control.
        //
        // transform() rather than editing form.data, so the row the user is
        // looking at stays on screen.
        form.transform((data) => ({
            ...data,
            controls: (data.controls ?? []).filter((control) => (control.description ?? '').trim() !== ''),
        }));

        if (isEditing) {
            form.put(route('rcsa.universe.update', editing.id), options);
        } else {
            form.post(route('rcsa.universe.store'), options);
        }
    };

    /* ------------------------------------------------------------------ */
    /*  Keyboard                                                           */
    /* ------------------------------------------------------------------ */

    useEffect(() => {
        if (!open) return;

        const onKey = (e) => {
            if (e.key === 'Escape') {
                onClose();
                return;
            }
            // Ctrl/Cmd+Enter is Save & Add Another — the loop this panel exists
            // for. Plain Enter is left alone so it still moves between fields
            // and inserts newlines in the textareas.
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                submit(!isEditing);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, isEditing, form.data]);

    if (!open) return null;

    const err = (field) => form.errors[field];

    return (
        <div className="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-label="Add risk">
            <div className="absolute inset-0 bg-black/30" onClick={onClose} aria-hidden="true" />

            <div className="relative z-10 flex h-full w-full max-w-2xl flex-col bg-white shadow-xl">
                {/* Header + step rail */}
                <div className="border-b border-gray-200 px-6 py-4">
                    <div className="flex items-start justify-between">
                        <div>
                            <h2 className="text-lg font-semibold text-gray-800">
                                {isEditing ? `Edit ${editing.risk_no}` : 'Add Risk'}
                            </h2>
                            <p className="text-sm text-gray-500">
                                {isEditing
                                    ? 'Change this universe row and its controls.'
                                    : 'Place the risk, describe it, then list the controls that mitigate it.'}
                            </p>
                        </div>
                        <button onClick={onClose} className="text-gray-400 hover:text-gray-600" aria-label="Close">
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div className="mt-4 flex gap-2">
                        {STEPS.map((s, i) => (
                            <button
                                key={s.key}
                                type="button"
                                onClick={() => setStep(s.key)}
                                className={`flex items-center gap-2 rounded-md px-3 py-1.5 text-sm ${
                                    step === s.key
                                        ? 'bg-[var(--color-primary)] text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                }`}
                            >
                                <span className="text-xs opacity-70">{i + 1}</span>
                                {s.label}
                            </button>
                        ))}
                    </div>
                </div>

                {justSaved && (
                    <div className="border-b border-green-200 bg-green-50 px-6 py-2 text-sm text-green-800">
                        Saved. The business unit, process and sub-process are kept — carry on with the next risk.
                    </div>
                )}

                {/*
                  * A summary, not only per-field errors: the fields are spread
                  * across three steps, so a failure on a step the user is not
                  * looking at would otherwise be invisible and Save would
                  * appear to do nothing.
                  */}
                {Object.keys(form.errors).length > 0 && (
                    <div className="border-b border-red-200 bg-red-50 px-6 py-3">
                        <p className="text-sm font-medium text-red-800">
                            This risk could not be saved:
                        </p>
                        <ul className="mt-1 space-y-0.5 text-sm text-red-700">
                            {Object.entries(form.errors).map(([field, message]) => (
                                <li key={field}>
                                    <button
                                        type="button"
                                        onClick={() => setStep(stepOfError(field))}
                                        className="underline underline-offset-2"
                                    >
                                        {message}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Body */}
                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {step === 'placement' && (
                        <div className="space-y-4">
                            <Field label="Business Unit" error={err('business_unit_id')} required>
                                <select
                                    autoFocus
                                    className="filter-select w-full"
                                    value={form.data.business_unit_id}
                                    onChange={(e) => {
                                        form.setData((d) => ({
                                            ...d,
                                            business_unit_id: e.target.value,
                                            process_id: '',
                                            sub_process_id: '',
                                        }));
                                    }}
                                >
                                    <option value="">Choose a business unit…</option>
                                    {(options.businessUnits ?? []).map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.code ? `${u.code} — ${u.name}` : u.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field
                                label="Process"
                                error={err('process_id')}
                                action={
                                    <button
                                        type="button"
                                        className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                        onClick={() => openCreate('process')}
                                        disabled={!form.data.business_unit_id}
                                    >
                                        + Create process
                                    </button>
                                }
                            >
                                <select
                                    className="filter-select w-full"
                                    value={form.data.process_id}
                                    onChange={(e) =>
                                        form.setData((d) => ({ ...d, process_id: e.target.value, sub_process_id: '' }))
                                    }
                                    disabled={!form.data.business_unit_id}
                                >
                                    <option value="">Choose a process…</option>
                                    {processes.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                                {inlineCreate('process')}
                            </Field>

                            <Field
                                label="Sub-Process"
                                error={err('sub_process_id')}
                                action={
                                    <button
                                        type="button"
                                        className="text-xs font-medium text-[var(--color-primary)] hover:underline"
                                        onClick={() => openCreate('sub_process')}
                                        disabled={!form.data.process_id}
                                    >
                                        + Create sub-process
                                    </button>
                                }
                            >
                                <select
                                    className="filter-select w-full"
                                    value={form.data.sub_process_id}
                                    onChange={(e) => form.setData('sub_process_id', e.target.value)}
                                    disabled={!form.data.process_id}
                                >
                                    <option value="">Choose a sub-process…</option>
                                    {subProcesses.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                                {inlineCreate('sub_process')}
                            </Field>

                            <Field label="Systems" error={err('system_ids')}>
                                <div className="flex flex-wrap gap-2">
                                    {(options.systems ?? []).length === 0 && (
                                        <p className="text-sm text-gray-400">
                                            No systems registered yet.
                                        </p>
                                    )}
                                    {(options.systems ?? []).map((s) => {
                                        const selected = form.data.system_ids.map(String).includes(String(s.id));

                                        return (
                                            <button
                                                key={s.id}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'system_ids',
                                                        selected
                                                            ? form.data.system_ids.filter(
                                                                  (id) => String(id) !== String(s.id),
                                                              )
                                                            : [...form.data.system_ids, s.id],
                                                    )
                                                }
                                                className={`rounded-full border px-3 py-1 text-xs ${
                                                    selected
                                                        ? 'border-[var(--color-primary)] bg-blue-50 text-[var(--color-primary)]'
                                                        : 'border-gray-300 text-gray-600 hover:border-gray-400'
                                                }`}
                                                aria-pressed={selected}
                                            >
                                                {s.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </Field>
                        </div>
                    )}

                    {step === 'risk' && (
                        <div className="space-y-4">
                            <Field label="Risk No." error={err('risk_no')}>
                                <input
                                    type="text"
                                    className="filter-input w-full"
                                    placeholder="Generated on save — e.g. RETAIL-R7"
                                    value={form.data.risk_no}
                                    onChange={(e) => form.setData('risk_no', e.target.value)}
                                />
                                <p className="mt-1 text-xs text-gray-500">
                                    Leave blank and the next number for this business unit is generated.
                                </p>
                            </Field>

                            <Field label="Potential Risk" error={err('potential_risk')} required>
                                <textarea
                                    ref={potentialRiskRef}
                                    rows={3}
                                    className="filter-input w-full"
                                    placeholder="What could go wrong, and what would follow from it?"
                                    value={form.data.potential_risk}
                                    onChange={(e) => form.setData('potential_risk', e.target.value)}
                                />
                            </Field>

                            <Field label="Risk Driver (root cause)" error={err('risk_driver')}>
                                <textarea
                                    rows={2}
                                    className="filter-input w-full"
                                    value={form.data.risk_driver}
                                    onChange={(e) => form.setData('risk_driver', e.target.value)}
                                />
                            </Field>

                            <Field label="Risk Category" error={err('risk_category')} required>
                                <select
                                    className="filter-select w-full"
                                    value={form.data.risk_category}
                                    onChange={(e) => form.setData('risk_category', e.target.value)}
                                >
                                    <option value="">Choose a category…</option>
                                    {(options.categories ?? []).map((c) => (
                                        <option key={c} value={c}>
                                            {c}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {form.data.risk_category === 'Others' && (
                                <Field
                                    label="Which categories apply?"
                                    error={err('secondary_categories')}
                                    required
                                >
                                    <TagInput
                                        values={form.data.secondary_categories}
                                        onChange={(v) => form.setData('secondary_categories', v)}
                                    />
                                </Field>
                            )}

                            <Field label="Risk Owner" error={err('owner_id')}>
                                <select
                                    className="filter-select w-full"
                                    value={form.data.owner_id}
                                    onChange={(e) => form.setData('owner_id', e.target.value)}
                                >
                                    <option value="">Unassigned</option>
                                    {(options.owners ?? []).map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    )}

                    {step === 'controls' && (
                        <div className="space-y-4">
                            <p className="text-sm text-gray-500">
                                The controls already in place. What they achieve is rated during the assessment, not
                                here.
                            </p>

                            {form.data.controls.map((control, index) => (
                                <div key={index} className="rounded-lg border border-gray-200 p-4">
                                    <div className="mb-3 flex items-center justify-between">
                                        <span className="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                            Control {index + 1}
                                        </span>
                                        {form.data.controls.length > 1 && (
                                            <button
                                                type="button"
                                                onClick={() => removeControl(index)}
                                                className="text-xs text-red-600 hover:underline"
                                            >
                                                Remove
                                            </button>
                                        )}
                                    </div>

                                    <Field label="Description" error={err(`controls.${index}.description`)}>
                                        <textarea
                                            rows={2}
                                            className="filter-input w-full"
                                            value={control.description}
                                            onChange={(e) => setControl(index, 'description', e.target.value)}
                                        />
                                    </Field>

                                    <div className="mt-3 grid grid-cols-2 gap-3">
                                        <Field label="Type">
                                            <select
                                                className="filter-select w-full"
                                                value={control.control_type}
                                                onChange={(e) => setControl(index, 'control_type', e.target.value)}
                                            >
                                                <option value="">—</option>
                                                {(options.controlTypes ?? []).map((t) => (
                                                    <option key={t} value={t}>
                                                        {t.replace(/_/g, ' ')}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field label="Frequency">
                                            <select
                                                className="filter-select w-full"
                                                value={control.frequency}
                                                onChange={(e) => setControl(index, 'frequency', e.target.value)}
                                            >
                                                <option value="">—</option>
                                                {(options.controlFrequencies ?? []).map((f) => (
                                                    <option key={f} value={f}>
                                                        {f.replace(/_/g, ' ')}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <Field label="Control Owner">
                                            <select
                                                className="filter-select w-full"
                                                value={control.control_owner_id}
                                                onChange={(e) => setControl(index, 'control_owner_id', e.target.value)}
                                            >
                                                <option value="">Unassigned</option>
                                                {(options.owners ?? []).map((u) => (
                                                    <option key={u.id} value={u.id}>
                                                        {u.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>

                                        <label className="flex items-end gap-2 pb-2 text-sm text-gray-600">
                                            <input
                                                type="checkbox"
                                                checked={!!control.is_key}
                                                onChange={(e) => setControl(index, 'is_key', e.target.checked)}
                                                className="rounded border-gray-300"
                                            />
                                            Key control
                                        </label>
                                    </div>
                                </div>
                            ))}

                            <button
                                type="button"
                                onClick={addControl}
                                className="btn-secondary inline-flex items-center gap-2 text-sm"
                            >
                                + Add another control
                            </button>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between border-t border-gray-200 px-6 py-4">
                    <p className="text-xs text-gray-400">
                        {isEditing ? 'Ctrl+Enter saves · Esc closes' : 'Ctrl+Enter saves and starts the next · Esc closes'}
                    </p>
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={onClose} className="btn-secondary text-sm">
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={() => submit(false)}
                            className="btn-secondary text-sm"
                            disabled={form.processing}
                        >
                            Save
                        </button>
                        {!isEditing && (
                            <button
                                type="button"
                                onClick={() => submit(true)}
                                className="btn-primary text-sm"
                                disabled={form.processing}
                            >
                                Save &amp; Add Another
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function Field({ label, error, required, action, children }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between">
                <label className="text-sm font-medium text-gray-700">
                    {label}
                    {required && <span className="ml-0.5 text-red-500">*</span>}
                </label>
                {action}
            </div>
            {children}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * Column I as a tag list rather than free text, so "how many risks touch AML"
 * stays a query rather than a search.
 */
function TagInput({ values, onChange }) {
    const [draft, setDraft] = useState('');

    const commit = () => {
        const value = draft.trim();
        if (value === '' || values.includes(value)) {
            setDraft('');

            return;
        }
        onChange([...values, value]);
        setDraft('');
    };

    return (
        <div>
            <div className="mb-2 flex flex-wrap gap-2">
                {values.map((value) => (
                    <span
                        key={value}
                        className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                    >
                        {value}
                        <button
                            type="button"
                            onClick={() => onChange(values.filter((v) => v !== value))}
                            className="text-gray-400 hover:text-red-500"
                            aria-label={`Remove ${value}`}
                        >
                            ×
                        </button>
                    </span>
                ))}
            </div>
            <input
                type="text"
                className="filter-input w-full"
                placeholder="Type a category and press Enter"
                value={draft}
                onChange={(e) => setDraft(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        commit();
                    }
                }}
                onBlur={commit}
            />
        </div>
    );
}
