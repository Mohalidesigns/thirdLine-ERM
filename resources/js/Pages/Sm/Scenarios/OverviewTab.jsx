import { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import FormField from '@thirdline/ui/Components/FormField';
import Icon from '@thirdline/ui/Components/Icon';
import NotesLog, { NotesCount } from '@/Components/Sm/NotesLog';
import OpEventTypePicker from '@/Components/Sm/OpEventTypePicker';
import SignoffPanel from '@/Components/Sm/SignoffPanel';
import useRegionBackup, { backupTime } from '@/Components/Sm/useRegionBackup';
import { formatDay, groupDigits, trimDecimal } from '@/Components/Sm/format';

/**
 * The Overview & Governance tab (spec 02).
 *
 * A BESPOKE FORM, NOT A `DynamicForm`, for the four verified blockers recorded in
 * spec 02 §2: `DynamicForm`'s `object_ref` case renders `<input type="number">`
 * for a foreign key; its `bool` case cannot express "not answered" because
 * `truthy(value)` renders null and false identically; requiredness here varies by
 * lifecycle status while a schema is built per object type; and its `text` case is
 * a fixed `rows={3}` textarea where a scenario's background runs to hundreds of
 * words. The tab is hand-composed from the same primitives `DynamicForm` uses.
 *
 * FIELD CODES ARE THE FROZEN COLUMN NAMES (§A1.2). They are the `name` attributes
 * on the wire, the keys in the 422 error object and the anchors
 * `gate.missing[].field` points at, so all three surfaces are one vocabulary.
 * Each renders with `id="field-{code}"` and `scroll-mt-24` so a readiness-strip
 * link can focus it.
 *
 * ONE SAVE REGION. This tab's Save posts only its own keys to `PUT {scenario}`,
 * which is a PARTIAL update: a whole-object write would null the Qualitative
 * tab's columns, and the Qualitative tab's own write would null `background`,
 * `rationale` and every governance field here — silently, with a 200 (spec 01
 * §6.1).
 */

/** The 4 000-character cap is a UI choice, not a schema limit (spec 02 §5.8). */
const NARRATIVE_MAX = 4000;
/** The counter is noise on a narrative field until it is close to the cap. */
const COUNTER_FROM = 3000;
const NAME_MAX = 250;

/**
 * The 422 keys THIS save region owns. The summary must not swallow the notes
 * log's `kind`/`body` (its own write path, spec 02 §7) or the Qualitative tab's
 * `causes.*`/`allocations*` keys, because each of those has its own summary in
 * its own region and an entry here would link to a `#field-{key}` anchor that
 * does not exist on this tab (spec 01 §6.2).
 *
 * `validated_on` is deliberately absent: it is read-only context, never posted,
 * and `UpdateScenarioRequest` has no rule for it, so the server could not return
 * an error on it even if it were.
 */
const OVERVIEW_ERROR_KEYS = [
    'name',
    'business_unit_id',
    'risk_taxonomy_node_id',
    'nature',
    'weighting',
    'currency',
    'fx_rate_id',
    'risk_id',
    'background',
    'rationale',
    'designed_on',
    'review_due_on',
];

export default function OverviewTab({
    scenario,
    values,
    errors,
    can,
    gate,
    transitions,
    urls,
    baseCurrency,
    businessUnits,
    opEventTypes,
    natures,
    weightings,
    currencies,
    fxRates,
    signoffs,
    notes,
    notesMeta,
    noteKinds,
    onSaved,
    onNotesPosted,
    onLoadEarlierNotes,
    onDirtyChange,
    saveRef,
}) {
    const editable = Boolean(can?.update);
    const missing = (gate?.missing || []).filter((item) => item.tab === 'overview').map((item) => item.field);
    // A field is marked required only where spec 02 §4's table says so FOR THE
    // CURRENT STATUS. The gate fields carry no red `*` while the scenario is
    // still being drafted — "a form covered in asterisks for fields nobody has to
    // fill teaches users to ignore asterisks" — and the requirement is stated by
    // the readiness strip instead, which links to `#field-{code}`. The asterisk
    // appears once the `designed` transition is actually on offer, i.e. when the
    // server's own gate is the thing standing between the user and the move.
    const atGate = (transitions || []).some((transition) => transition.to === 'designed');
    const required = (code) => code === 'name' || (atGate && missing.includes(code));

    const units = businessUnits || [];
    const singleUnit = units.length === 1 ? units[0] : null;
    const foreignCurrency = values?.currency && baseCurrency && values.currency !== baseCurrency;

    const { data, setData, put, processing, reset, isDirty } = useFormRegion(values, editable);
    const backup = useRegionBackup(scenario.id, 'overview', data, editable);
    const [savedAt, setSavedAt] = useState(null);
    const errorKeys = Object.keys(errors);
    const summaryRef = useRef(null);

    useEffect(() => {
        if (errorKeys.length > 0) {
            summaryRef.current?.focus();
            summaryRef.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [errors]);

    // Report this region's dirty flag to the shell, which owns the single
    // unsaved-changes guard for the whole workspace (spec 01 §6.3). Dirty tracking
    // is per save region, so a save here clears only this tab's flag.
    useEffect(() => {
        onDirtyChange?.(editable && isDirty);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isDirty, editable]);

    const submit = (event) => {
        event.preventDefault();

        put(urls.scenario, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                backup.clear();
                setSavedAt(new Date().toISOString());
                onSaved?.();
            },
        });
    };

    return (
        <div className="mx-auto max-w-4xl space-y-4">
            {backup.offer && (
                <div role="status" className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-gray-800">
                    We kept entries you hadn't saved{backupTime(backup.offer.savedAt) ? ` from ${backupTime(backup.offer.savedAt)}` : ''}.{' '}
                    <button
                        type="button"
                        className="font-semibold text-[var(--color-primary)] underline"
                        onClick={() => {
                            const restored = backup.take();
                            if (restored) {
                                Object.entries(restored).forEach(([key, value]) => setData(key, value));
                            }
                        }}
                    >
                        Restore them
                    </button>{' '}
                    ·{' '}
                    <button type="button" className="font-semibold text-[var(--color-primary)] underline" onClick={backup.dismiss}>
                        Discard
                    </button>
                </div>
            )}

            {scenario.status === 'archived' && (
                <p role="note" className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                    This scenario is archived. It stays on the audit trail and appears in no live list.
                </p>
            )}

            <ErrorSummary
                summaryRef={summaryRef}
                errors={errors}
                keys={errorKeys.filter((key) => OVERVIEW_ERROR_KEYS.includes(key))}
                region="Nothing was saved. Your entries are still on this page."
            />

            {/* ── Identity ─────────────────────────────────────────────── */}
            <Card title="Identity" list={!editable}>
                <ReadRow label="Reference" value={scenario.reference} mono />

                {editable ? (
                    <>
                        <div id="field-name" className="scroll-mt-24">
                            <FormField
                                label="Scenario name"
                                htmlFor="name"
                                required
                                error={errors.name}
                                hint="Describe the event, not the risk. 'Loss of key staff — succession planning failure' rather than 'People risk'."
                            >
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    className="form-input"
                                    maxLength={NAME_MAX}
                                    value={data.name ?? ''}
                                    onChange={(event) => setData('name', event.target.value)}
                                    aria-invalid={errors.name ? 'true' : undefined}
                                />
                            </FormField>
                            <Counter length={(data.name ?? '').length} max={NAME_MAX} from={NAME_MAX - 50} />
                        </div>

                        <div id="field-business_unit_id" className="scroll-mt-24">
                            {singleUnit ? (
                                <ReadOnlyUnit unit={singleUnit} />
                            ) : (
                                <FormField
                                    label="Business unit"
                                    htmlFor="business_unit_id"
                                    error={errors.business_unit_id}
                                    hint={
                                        units.length === 0
                                            ? undefined
                                            : 'The unit that owns this scenario. Whether it is a group, standard or local scenario is set by Nature below.'
                                    }
                                >
                                    <select
                                        id="business_unit_id"
                                        name="business_unit_id"
                                        className="form-input"
                                        value={data.business_unit_id ?? ''}
                                        onChange={(event) =>
                                            setData('business_unit_id', event.target.value === '' ? null : Number(event.target.value))
                                        }
                                        aria-invalid={errors.business_unit_id ? 'true' : undefined}
                                    >
                                        <option value="">Choose a business unit…</option>
                                        {units.map((unit) => (
                                            <option key={unit.id} value={unit.id}>
                                                {unit.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            )}
                            {units.length === 0 && (
                                <AmberNote>
                                    No business unit is configured for your organisation, so this scenario is unassigned.
                                    Add units under Scoping to give it an owner.
                                </AmberNote>
                            )}
                        </div>
                    </>
                ) : (
                    <>
                        <ReadRow label="Scenario name" value={values?.name} />
                        <ReadRow label="Business unit" value={scenario.business_unit?.name} />
                    </>
                )}
            </Card>

            {/* ── Classification ───────────────────────────────────────── */}
            <Card title="Classification" list={!editable}>
                <div id="field-risk_taxonomy_node_id" className="scroll-mt-24">
                    {editable ? (
                        <>
                            <span className="form-label">
                                OP event type
                                {required('risk_taxonomy_node_id') && <RequiredMark />}
                            </span>
                            <OpEventTypePicker
                                options={opEventTypes}
                                value={data.risk_taxonomy_node_id}
                                onChange={(id) => setData('risk_taxonomy_node_id', id)}
                                error={errors.risk_taxonomy_node_id}
                                required={required('risk_taxonomy_node_id')}
                            />
                        </>
                    ) : (
                        <ReadRow label="OP event type" value={scenario.op_event_type?.name} />
                    )}
                </div>

                {editable ? (
                    <>
                        <div id="field-nature" className="scroll-mt-24">
                            <FormField
                                label="Nature of scenario"
                                htmlFor="nature"
                                error={errors.nature}
                                hint="A Group scenario is designed centrally and run across the group; a Standard scenario is a group template applied locally; a Local scenario is this organisation's own."
                            >
                                <select
                                    id="nature"
                                    name="nature"
                                    className="form-input"
                                    value={data.nature ?? ''}
                                    onChange={(event) => setData('nature', event.target.value)}
                                    aria-invalid={errors.nature ? 'true' : undefined}
                                >
                                    {(natures || []).map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                        </div>

                        <div id="field-weighting" className="scroll-mt-24">
                            {/* `weighting` IS NEVER DEFAULTED: §A1.2 makes it
                                nullable with no default, because a default
                                "would be a fabricated figure on every scenario
                                nobody has weighted". So the empty option stays
                                and an unset weighting reads `—`. */}
                            <FormField
                                label="Weighting factor"
                                htmlFor="weighting"
                                error={errors.weighting}
                                hint="How much weight this scenario carries in the organisation's operational-risk profile."
                            >
                                <select
                                    id="weighting"
                                    name="weighting"
                                    className="form-input"
                                    value={data.weighting ?? ''}
                                    onChange={(event) => setData('weighting', event.target.value === '' ? null : event.target.value)}
                                    aria-invalid={errors.weighting ? 'true' : undefined}
                                >
                                    <option value="">Choose a weighting…</option>
                                    {(weightings || []).map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                        </div>
                    </>
                ) : (
                    <>
                        <ReadRow label="Nature of scenario" value={scenario.nature_label} />
                        <ReadRow label="Weighting factor" value={scenario.weighting_label} />
                    </>
                )}
            </Card>

            {/* ── Currency ─────────────────────────────────────────────── */}
            <Card title="Currency" list={!editable}>
                {editable ? (
                    <>
                        <div id="field-currency" className="scroll-mt-24">
                            <FormField label="Currency" htmlFor="currency" error={errors.currency}>
                                <select
                                    id="currency"
                                    name="currency"
                                    className="form-input sm:max-w-xs"
                                    value={data.currency ?? ''}
                                    onChange={(event) => setData('currency', event.target.value)}
                                    aria-invalid={errors.currency ? 'true' : undefined}
                                >
                                    {(currencies || []).map((option) => (
                                        <option key={option.code} value={option.code}>
                                            {option.code}
                                            {option.name ? ` — ${option.name}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                            <p className="mt-1 text-xs text-gray-600">Base currency for this tenant: {baseCurrency}.</p>
                        </div>

                        {/* `fx_rate_id` renders ONLY when `currency !== baseCurrency`.
                            There is no converted figure anywhere in Phase 1 — there
                            is no amount on this tab to convert, and rendering
                            "≈ ₦0 equivalent" against an empty impact would be a
                            fabricated number (spec 02 §5.6). */}
                        {foreignCurrency && (
                            <div id="field-fx_rate_id" className="scroll-mt-24">
                                {(fxRates || []).length === 0 ? (
                                    <>
                                        <span className="form-label">Exchange rate</span>
                                        <AmberNote>
                                            No {data.currency} exchange rate is on record. The scenario can be saved, but
                                            a foreign-currency scenario with no rate cannot be converted.
                                            {urls.fxRates && (
                                                <>
                                                    {' '}
                                                    <a href={urls.fxRates} className="underline">
                                                        Open the FX rates screen
                                                    </a>
                                                </>
                                            )}
                                        </AmberNote>
                                    </>
                                ) : (
                                    <FormField
                                        label="Exchange rate"
                                        htmlFor="fx_rate_id"
                                        error={errors.fx_rate_id}
                                        hint="The rate you pick is stored with the scenario, so a signed-off figure never moves when the rate table does."
                                    >
                                        <select
                                            id="fx_rate_id"
                                            name="fx_rate_id"
                                            className="form-input"
                                            value={data.fx_rate_id ?? ''}
                                            onChange={(event) =>
                                                setData('fx_rate_id', event.target.value === '' ? null : Number(event.target.value))
                                            }
                                            aria-invalid={errors.fx_rate_id ? 'true' : undefined}
                                        >
                                            <option value="">Choose a rate…</option>
                                            {(fxRates || []).map((rate) => (
                                                <option key={rate.id} value={rate.id}>
                                                    1 {rate.from} = {trimDecimal(rate.rate)} {rate.to}
                                                    {rate.rate_date ? ` · effective ${formatDay(rate.rate_date)}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                )}
                            </div>
                        )}
                    </>
                ) : (
                    <>
                        <ReadRow label="Currency" value={scenario.currency} />
                        <ReadRow
                            label="Exchange rate"
                            value={
                                scenario.fx
                                    ? [
                                        scenario.fx.from && scenario.fx.to ? `1 ${scenario.fx.from} = ${trimDecimal(scenario.fx.rate)} ${scenario.fx.to}` : trimDecimal(scenario.fx.rate),
                                        scenario.fx.rate_date ? `Rate effective ${formatDay(scenario.fx.rate_date)}` : null,
                                    ].filter(Boolean).join(' · ')
                                    : null
                            }
                        />
                    </>
                )}
            </Card>

            {/* ── Linked risk ──────────────────────────────────────────── */}
            <Card title="Linked risk" list={!editable}>
                <div id="field-risk_id" className="scroll-mt-24">
                    {editable ? (
                        <RiskPicker
                            risk={scenario.risk}
                            value={data.risk_id}
                            searchUrl={urls.riskSearch}
                            error={errors.risk_id}
                            onChange={(id) => setData('risk_id', id)}
                        />
                    ) : (
                        <LinkedRiskRead risk={scenario.risk} />
                    )}
                </div>
            </Card>

            {/* ── Background & rationale ───────────────────────────────── */}
            <Card title="Background & rationale" list={!editable}>
                {editable ? (
                    <>
                        <NarrativeField
                            code="background"
                            label="Background and context"
                            hint="How the risk materialises: what happens, to whom, over what period. This is the narrative a validator reads first."
                            value={data.background}
                            error={errors.background}
                            required={required('background')}
                            onChange={(value) => setData('background', value)}
                        />
                        <NarrativeField
                            code="rationale"
                            label="Why this scenario was designed"
                            hint="What made this worth assessing — a loss event, a KRI breach, an emerging risk, a regulator's question, a workshop."
                            value={data.rationale}
                            error={errors.rationale}
                            required={required('rationale')}
                            onChange={(value) => setData('rationale', value)}
                        />
                    </>
                ) : (
                    <>
                        <ReadBlock label="Background and context" value={values?.background} />
                        <ReadBlock label="Why this scenario was designed" value={values?.rationale} />
                    </>
                )}
            </Card>

            {/* ── Dates & review ───────────────────────────────────────── */}
            <Card title="Dates & review" list={!editable}>
                {editable ? (
                    <>
                        <div id="field-designed_on" className="scroll-mt-24">
                            <FormField
                                label="Date of design"
                                htmlFor="designed_on"
                                error={errors.designed_on}
                                hint="When the design of this scenario was completed."
                            >
                                <input
                                    id="designed_on"
                                    name="designed_on"
                                    type="date"
                                    className="form-input sm:max-w-xs"
                                    value={data.designed_on ?? ''}
                                    onChange={(event) => setData('designed_on', event.target.value === '' ? null : event.target.value)}
                                    aria-invalid={errors.designed_on ? 'true' : undefined}
                                />
                            </FormField>
                            {/* NOT pre-filled with today: a date field that
                                silently defaults to today is how a scenario
                                ends up claiming a design date nobody agreed
                                (spec 02 §5.9). */}
                            <DayReading value={data.designed_on} />
                        </div>

                        <div id="field-review_due_on" className="scroll-mt-24">
                            <FormField
                                label="Next review due"
                                htmlFor="review_due_on"
                                error={errors.review_due_on}
                                hint="When this scenario is next due for review. Required at sign-off."
                            >
                                <input
                                    id="review_due_on"
                                    name="review_due_on"
                                    type="date"
                                    className="form-input sm:max-w-xs"
                                    value={data.review_due_on ?? ''}
                                    onChange={(event) => setData('review_due_on', event.target.value === '' ? null : event.target.value)}
                                    aria-invalid={errors.review_due_on ? 'true' : undefined}
                                />
                            </FormField>
                            <DayReading value={data.review_due_on} />
                        </div>
                    </>
                ) : (
                    <>
                        <ReadRow label="Date of design" value={formatIfSet(values?.designed_on)} />
                        <ReadRow label="Next review due" value={formatIfSet(values?.review_due_on)} />
                    </>
                )}

                {/* `validated_on` IS NEVER A FIELD, in either presentation. The
                    server stamps it on the `validated` transition; a designer who
                    can type one can type a date nobody validated anything on. It
                    shows `—` until then — never today's date, never
                    blank-with-an-asterisk (spec 02 §4, §5.9). */}
                <ReadRow label="Date of validation" value={formatIfSet(values?.validated_on)} note="Stamped by the server" />
            </Card>

            {/* ── Sign-off ─────────────────────────────────────────────── */}
            <Card title="Sign-off">
                <SignoffPanel signoffs={signoffs} />
            </Card>

            {/* ── Notes log ────────────────────────────────────────────── */}
            <Card title="Notes log" aside={<NotesCount notesMeta={notesMeta} />}>
                <NotesLog
                    notes={notes}
                    notesMeta={notesMeta}
                    noteKinds={noteKinds}
                    // Gated on `can.note`, NOT `can.update` (spec 01 §3, §A1.10):
                    // a risk owner holding `scenario.view` + `scenario.note` can
                    // add notes here while every field above is read-only.
                    canNote={Boolean(can?.note)}
                    // The notes log has its OWN write path (`POST urls.notes`), so
                    // its 422 keys are `kind` and `body` and never collide with a
                    // governance key — `UpdateScenarioRequest` has neither.
                    errors={{ kind: errors.kind, body: errors.body }}
                    earlierUrl={urls.notesEarlier}
                    onReload={(payload, after, earlier) => {
                        if (earlier) {
                            onLoadEarlierNotes?.(earlier);
                            return;
                        }

                        onNotesPosted?.(payload, after);
                    }}
                />
            </Card>

            {editable && (
                <div className="flex flex-wrap items-center gap-3 pb-2">
                    <button
                        ref={saveRef}
                        type="submit"
                        form="overview-form"
                        className="btn-primary text-sm"
                        disabled={processing}
                        aria-busy={processing || undefined}
                        aria-describedby={savedAt ? 'overview-last-saved' : undefined}
                    >
                        {processing ? 'Saving…' : 'Save Overview & Governance'}
                    </button>
                    {savedAt && (
                        <span id="overview-last-saved" className="text-xs text-gray-600">
                            Last saved {backupTime(savedAt)} Lagos time
                        </span>
                    )}
                    {isDirty && <span className="text-xs text-gray-600">Unsaved changes</span>}
                </div>
            )}

            {/* The form element wraps nothing visually — the Save above submits it
                by id, so the button can sit outside every card and still be this
                tab's only submit (spec 02 §3, spec 01 §6.1). */}
            {editable && <form id="overview-form" onSubmit={submit} noValidate className="sr-only" aria-hidden="true" />}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Form state                                                         */
/* ------------------------------------------------------------------ */

/**
 * The Overview region's own form state, seeded from `values` and re-seeded when
 * the server sends a fresh payload after a save.
 *
 * Only this tab's keys exist here, which is what makes the `PUT` partial: a key
 * that was never loaded cannot be posted, so a Qualitative save can never null
 * `background` and an Overview save can never null an exposure column.
 *
 * The component is NOT remounted on save — spec 02 §9 and spec 01 §6.1 require
 * focus to stay on Save — so the re-seed is an effect keyed on the identity of
 * the server's `values` object, which the presenter rebuilds on every response.
 */
function useFormRegion(values, editable) {
    const initial = {
        name: values?.name ?? '',
        business_unit_id: values?.business_unit_id ?? null,
        risk_taxonomy_node_id: values?.risk_taxonomy_node_id ?? null,
        nature: values?.nature ?? '',
        weighting: values?.weighting ?? '',
        currency: values?.currency ?? '',
        fx_rate_id: values?.fx_rate_id ?? null,
        risk_id: values?.risk_id ?? null,
        background: values?.background ?? '',
        rationale: values?.rationale ?? '',
        designed_on: values?.designed_on ?? '',
        review_due_on: values?.review_due_on ?? '',
    };

    const form = useForm(initial);
    const seededFrom = useRef(values);

    useEffect(() => {
        if (!editable || seededFrom.current === values) {
            return;
        }

        seededFrom.current = values;
        form.setData({
            name: values?.name ?? '',
            business_unit_id: values?.business_unit_id ?? null,
            risk_taxonomy_node_id: values?.risk_taxonomy_node_id ?? null,
            nature: values?.nature ?? '',
            weighting: values?.weighting ?? '',
            currency: values?.currency ?? '',
            fx_rate_id: values?.fx_rate_id ?? null,
            risk_id: values?.risk_id ?? null,
            background: values?.background ?? '',
            rationale: values?.rationale ?? '',
            designed_on: values?.designed_on ?? '',
            review_due_on: values?.review_due_on ?? '',
        });
        form.clearErrors();
        // Re-seed only when the server's payload identity changes; `form` is
        // stable enough for this purpose and depending on it would loop.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values, editable]);

    return form;
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                             */
/* ------------------------------------------------------------------ */

function Card({ title, aside, list = false, children }) {
    const Body = list ? 'dl' : 'div';

    return (
        <section className="rounded-xl border border-gray-200 bg-white">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <h2 className="text-sm font-semibold text-[#1A365D]">{title}</h2>
                {aside}
            </div>
            {/* A read-only card is a `dl` following `DynamicDetail`'s presentation
                (label above value, `—` for absent), not a set of disabled inputs:
                a disabled field reads as "temporarily unavailable" and invites
                retrying, while text reads as "this is the record" (spec 02 §9). */}
            <Body className="space-y-4 px-5 py-4">{children}</Body>
        </section>
    );
}

function ErrorSummary({ summaryRef, errors, keys, region }) {
    if (!keys || keys.length === 0) {
        return null;
    }

    return (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3">
            <h2 ref={summaryRef} tabIndex={-1} className="text-sm font-semibold text-red-800 focus:outline-none">
                Fix {keys.length} {keys.length === 1 ? 'item' : 'items'}
            </h2>
            <ul className="mt-1 list-inside list-disc text-sm text-red-700">
                {keys.map((key) => (
                    <li key={key}>
                        <a href={`#field-${key}`} className="underline">
                            {errors[key]}
                        </a>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-red-700">{region}</p>
        </div>
    );
}

function RequiredMark() {
    return (
        <>
            <span className="ml-0.5 text-red-600" aria-hidden="true">
                *
            </span>
            <span className="sr-only"> (required)</span>
        </>
    );
}

/** Label above value, `—` for absent — `DynamicDetail`'s presentation (spec 02 §2). */
function ReadRow({ label, value, mono = false, note }) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`mt-0.5 text-sm text-gray-900 ${mono ? 'font-mono' : ''}`}>
                {empty ? <span className="text-gray-600">—</span> : value}
                {note && <span className="ml-2 text-xs font-normal text-gray-500">{note}</span>}
            </dd>
        </div>
    );
}

function ReadBlock({ label, value }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            {value ? (
                <dd className="mt-0.5 whitespace-pre-line text-sm text-gray-900">{value}</dd>
            ) : (
                <dd className="mt-0.5 text-sm text-gray-600">—</dd>
            )}
        </div>
    );
}

function ReadOnlyUnit({ unit }) {
    return (
        <div>
            <p className="form-label mb-0">Business unit</p>
            <p className="text-sm text-gray-900">{unit.name}</p>
            <p className="mt-1 text-xs text-gray-600">Your organisation has one business unit.</p>
        </div>
    );
}

function AmberNote({ children }) {
    return (
        <div
            role="note"
            className="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
            <span>{children}</span>
        </div>
    );
}

function Counter({ length, max, from }) {
    if (length < from) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-gray-600">
            {groupDigits(length)} / {groupDigits(max)}
        </p>
    );
}

function NarrativeField({ code, label, hint, value, error, required: isRequired, onChange }) {
    const length = (value ?? '').length;

    return (
        <div id={`field-${code}`} className="scroll-mt-24">
            <FormField label={label} htmlFor={code} required={isRequired} error={error} hint={hint}>
                <textarea
                    id={code}
                    name={code}
                    rows={8}
                    maxLength={NARRATIVE_MAX}
                    className="form-input"
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                    aria-invalid={error ? 'true' : undefined}
                />
            </FormField>
            <Counter length={length} max={NARRATIVE_MAX} from={COUNTER_FROM} />
        </div>
    );
}

/** The reading form of a `Y-m-d` value, beside the input that holds the raw one. */
function DayReading({ value }) {
    if (!value) {
        return null;
    }

    return <p className="mt-1 text-xs text-gray-600">{formatDay(value)}</p>;
}

function formatIfSet(value) {
    return value ? formatDay(value) : null;
}

function LinkedRiskRead({ risk }) {
    if (!risk) {
        return (
            <div>
                <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Risk register entry</dt>
                <dd className="mt-0.5 text-sm text-gray-600">—</dd>
            </div>
        );
    }

    const text = `${risk.risk_code} · ${risk.title}`;

    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Risk register entry</dt>
            <dd className="mt-0.5 text-sm">
                {risk.url ? (
                    <a href={risk.url} target="_blank" rel="noopener" className="text-[var(--color-primary)] underline">
                        {text} (opens in a new tab)
                    </a>
                ) : (
                    // `url` is null for a caller without `risk.view`, and then the
                    // reference renders as PLAIN TEXT rather than a link that 403s
                    // (spec 02 §5.7). The prop is nullable for exactly that reason.
                    <span className="text-gray-900">{text}</span>
                )}
            </dd>
        </div>
    );
}

/**
 * The linked-risk type-ahead (spec 02 §5.7).
 *
 * AN ON-DEMAND ENDPOINT, NOT A SHIPPED LIST: a register is thousands of rows and
 * shipping it would cost 60 KB+ — five seconds at 100 kbps for a list most users
 * never open. Nothing is fetched until the user types ≥ 2 characters, the query
 * is debounced 350 ms, and results are capped at twenty by the server.
 *
 * SINGULAR, DELIBERATELY: §A1.2 refused an `sm_scenario_risks` pivot for Phase 1,
 * so this control must not grow a multi-select affordance in anticipation of one.
 */
function RiskPicker({ risk, value, searchUrl, error, onChange }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);
    const [searching, setSearching] = useState(false);
    const [capped, setCapped] = useState(false);
    const [searched, setSearched] = useState(false);
    const controller = useRef(null);

    useEffect(() => {
        const term = query.trim();

        if (term.length < 2) {
            setResults([]);
            setSearched(false);
            return undefined;
        }

        const timer = window.setTimeout(() => {
            controller.current?.abort();
            controller.current = new AbortController();
            setSearching(true);

            fetch(`${searchUrl}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.current.signal,
            })
                .then((response) => (response.ok ? response.json() : { data: [], capped: false }))
                .then((payload) => {
                    setResults(payload.data || []);
                    setCapped(Boolean(payload.capped));
                    setSearched(true);
                })
                .catch(() => {
                    // An aborted or failed search is not an error worth a banner:
                    // the words below already say nothing matched.
                    setResults([]);
                    setSearched(true);
                })
                .finally(() => setSearching(false));
        }, 350);

        return () => window.clearTimeout(timer);
    }, [query, searchUrl]);

    return (
        <div className="space-y-2">
            {risk && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-2">
                    <LinkedRiskRead risk={risk} />
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        className="ml-auto text-sm font-semibold text-gray-600 hover:text-red-700"
                    >
                        Remove
                        <span className="sr-only"> the linked risk</span>
                    </button>
                </div>
            )}

            {!risk && (
                <Combobox
                    value={null}
                    onChange={(option) => {
                        if (option) {
                            onChange(option.id);
                            setQuery('');
                            setResults([]);
                        }
                    }}
                    immediate
                >
                    <span className="form-label">Risk register entry</span>
                    <ComboboxInput
                        id="risk_id"
                        name="risk_id"
                        className="form-input text-sm"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Type to search the register…"
                        aria-busy={searching || undefined}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={error ? 'field-risk_id-error' : 'field-risk_id-hint'}
                        autoComplete="off"
                    />
                    <ComboboxOptions className="mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg empty:hidden">
                        {results.map((option) => (
                            <ComboboxOption
                                key={option.id}
                                value={option}
                                className="cursor-pointer px-3 py-2 text-sm data-[focus]:bg-blue-50"
                            >
                                {option.risk_code} · {option.title}
                            </ComboboxOption>
                        ))}
                    </ComboboxOptions>
                    <p id="field-risk_id-hint" className="mt-1 text-xs text-gray-600">
                        {searching
                            ? 'Searching the register…'
                            : searched && results.length === 0 && query.trim().length >= 2
                                ? <>No risk matches '{query.trim()}'. You can save the scenario without a link and add one later.</>
                                : 'Optional. Type at least two characters to search the risk register.'}
                        {capped && results.length > 0 && ' Showing the first 20 matches.'}
                    </p>
                    {/* A live count, debounced with the search rather than per
                        keystroke (spec 02 §10). */}
                    {searched && results.length > 0 && (
                        <p role="status" className="text-xs text-gray-600">
                            {results.length} {results.length === 1 ? 'risk matches' : 'risks match'} '{query.trim()}'
                        </p>
                    )}
                </Combobox>
            )}

            {error && (
                <p id="field-risk_id-error" className="text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
