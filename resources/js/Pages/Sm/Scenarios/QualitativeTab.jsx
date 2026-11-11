import { useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AllocationEditor from '@/Components/Sm/AllocationEditor';
import CauseChecklist from '@/Components/Sm/CauseChecklist';
import useRegionBackup, { backupTime } from '@/Components/Sm/useRegionBackup';
import { isExactly100, sumPercents } from '@/Components/Sm/format';

/**
 * The Qualitative tab (spec 03).
 *
 * THREE SAVE REGIONS, NOT ONE SAVE BUTTON, because §A1.11 gives this tab three
 * write routes (spec 03 §5.6):
 *
 *   Causes      → `PUT {scenario}/causes`       `{causes: {[code]: {note}}}`
 *   Allocation  → `PUT {scenario}/allocations`  `{allocations: [{business_unit_id, percent}]}`
 *   Exposures   → `PUT {scenario}`              the five exposure columns ONLY
 *
 * One Save firing three PUTs could succeed on causes and 422 on allocations, and
 * "Nothing was saved" would then be a lie. Three regions also match the workshop
 * — causes are ticked by the people in the room while allocation percentages are
 * negotiated afterwards with finance — and each ~1 KB write is retriable alone on
 * a bad link. The tab has NO page-level Save and must not grow one.
 *
 * THE EXPOSURE POST IS PARTIAL. It carries five keys and must leave `background`,
 * `rationale`, `name` and every governance field untouched: the Overview tab owns
 * those and this tab never loads them, so a whole-object write from here would
 * null them with a 200 response (spec 01 §6.1, standard §6).
 *
 * SAVING AT 60% MUST SUCCEED (spec 03 §5.2). §A1.5 puts the sum-to-100 rule at
 * the `in_design → designed` TRANSITION "and not on every draft save — a draft
 * legitimately sums to 60% mid-entry". The sum line shows the mismatch in red and
 * the Save button stays enabled: a screen that refused to save 60% would be
 * enforcing a stricter rule than the server has. The 422 on key `allocations`
 * comes from `TransitionScenarioRequest` at the transition, and renders here.
 *
 * NULL IS NEVER COERCED. `insurance_risk` and `financial_risk` are three-state
 * (Yes / No / Not assessed → `null`); both exposure scales carry an explicit unset
 * radio posting `null`; `eligible_to_transfer` alone is two-state because §A1.2
 * row 25 froze it NOT NULL default false.
 */
export default function QualitativeTab({ scenario, qualitative, qualitativeSummary, can, urls, notes, notesMeta, saveRef, onDirtyChange }) {
    const editable = Boolean(can?.update);
    const page = usePage();
    // The three regions post to three routes, so the shared error bag is split by
    // key rather than by request: `causes.*` is the causes region, `allocations*`
    // the allocation region, and the five exposure columns the third.
    const allErrors = page.props.errors || {};

    // Dirty tracking is PER SAVE REGION (spec 01 §6.3), so each card reports its
    // own flag and the shell's single guard sees the union.
    const [dirty, setDirty] = useState({ causes: false, allocations: false, exposures: false });
    const report = (region) => (value) => setDirty((prev) => ({ ...prev, [region]: value }));

    useEffect(() => {
        onDirtyChange?.(editable && Object.values(dirty).some(Boolean));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dirty, editable]);

    const causes = qualitative?.causes || { checklist: [], selected: {} };
    const allocation = qualitative?.allocation || { lines: [], options: [], saved_total: null };
    const exposures = qualitative?.exposures || {};
    const yesNo = qualitative?.yesNo || {};

    const causeErrors = useMemo(
        () => Object.fromEntries(Object.entries(allErrors).filter(([key]) => key.startsWith('causes'))),
        [allErrors],
    );
    const allocationErrors = useMemo(
        () => Object.fromEntries(Object.entries(allErrors).filter(([key]) => key.startsWith('allocations'))),
        [allErrors],
    );
    const exposureErrors = useMemo(
        () =>
            Object.fromEntries(
                Object.entries(allErrors).filter(([key]) =>
                    ['reputational_exposure', 'legal_compliance_exposure', 'insurance_risk', 'financial_risk', 'eligible_to_transfer'].includes(key),
                ),
            ),
        [allErrors],
    );

    return (
        <div className="mx-auto max-w-4xl space-y-4">
            <CausesCard
                scenario={scenario}
                checklist={causes.checklist}
                selected={causes.selected}
                editable={editable}
                errors={causeErrors}
                url={urls.causes}
                onDirty={report('causes')}
            />

            <AllocationCard
                scenario={scenario}
                allocation={allocation}
                editable={editable}
                errors={allocationErrors}
                url={urls.allocations}
                onDirty={report('allocations')}
            />

            <ExposureCards
                scenario={scenario}
                exposures={exposures}
                yesNo={yesNo}
                summary={qualitativeSummary}
                editable={editable}
                errors={exposureErrors}
                url={urls.scenario}
                notes={notes}
                notesMeta={notesMeta}
                saveRef={saveRef}
                onDirty={report('exposures')}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Region one — causes                                                */
/* ------------------------------------------------------------------ */

function CausesCard({ scenario, checklist, selected, editable, errors, url, onDirty }) {
    // One entry per checklist code. `on` is the tick; `note` survives an
    // un-tick for the rest of the session so a mis-click does not destroy a
    // paragraph — but the POSTED BODY OMITS the code entirely, because a note on
    // an unticked cause is not sent and only selected codes are persisted
    // (spec 03 §4.2).
    const [entries, setEntries] = useState(() => seedEntries(checklist, selected));
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(null);
    const seeded = useRef(checklist);

    useEffect(() => {
        // DO NOT RE-SEED AFTER A 422 (spec 01 §6.2: "Nothing was saved. Your
        // entries are still on this page"). A failed save returns a fresh
        // `checklist` array, and re-seeding from it would untick the boxes the
        // user just ticked and throw away the note the server asked for — the one
        // thing they must not lose while fixing the error.
        if (seeded.current === checklist || Object.keys(errors).length > 0) {
            return;
        }

        seeded.current = checklist;
        setEntries(seedEntries(checklist, selected));
        // `selected` is re-read from the same payload as `checklist`, so keying on
        // the checklist's identity is what re-seeds after a save.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [checklist, selected, errors]);

    const posted = useMemo(() => postBody(entries), [entries]);
    const dirty = useMemo(() => JSON.stringify(posted) !== JSON.stringify(seedBody(selected)), [posted, selected]);
    const backup = useRegionBackup(scenario.id, 'causes', entries, editable);
    const selectedCount = Object.values(entries).filter((entry) => entry.on).length;

    useEffect(() => {
        onDirty?.(dirty);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dirty]);

    const toggle = (code, on, note) => {
        setEntries((prev) => ({
            ...prev,
            [code]: { on, note: note === undefined ? (prev[code]?.note ?? '') : note },
        }));
    };

    const save = () => {
        setSaving(true);

        router.put(
            url,
            { causes: posted },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    backup.clear();
                    setSavedAt(new Date().toISOString());
                },
            },
        );
    };

    const selection = useMemo(() => {
        const out = {};

        Object.entries(entries).forEach(([code, entry]) => {
            if (entry.on) {
                out[code] = { note: entry.note.trim() === '' ? null : entry.note };
            }
        });

        return out;
    }, [entries]);

    return (
        <Card
            title="Causes"
            aside={<span className="text-xs text-gray-600">{selectedCount} selected</span>}
            errorKeys={Object.keys(errors)}
            errors={errors}
            region="The causes weren't saved. Your allocation and exposure answers are unaffected."
        >
            {backup.offer && <BackupStrip backup={backup} onRestore={(data) => setEntries(data)} />}

            <CauseChecklist
                checklist={checklist}
                selection={selection}
                readOnly={!editable}
                onChange={(code, on, note) => toggle(code, on, note)}
            />

            {editable && (
                <SaveRow
                    label="Save causes"
                    saving={saving}
                    savedAt={savedAt}
                    dirty={dirty}
                    onSave={save}
                    id="field-causes"
                />
            )}
        </Card>
    );
}

function seedEntries(checklist, selected) {
    const out = {};

    (checklist || []).forEach((item) => {
        const entry = selected?.[item.code];
        out[item.code] = { on: entry !== undefined, note: entry?.note ?? '' };
    });

    return out;
}

/** The posted body: selected codes only, keyed by code, note trimmed to null. */
function postBody(entries) {
    const out = {};

    Object.entries(entries).forEach(([code, entry]) => {
        if (!entry.on) {
            return;
        }

        out[code] = { note: entry.note.trim() === '' ? null : entry.note.trim() };
    });

    return out;
}

/** What the server last sent, in the posted shape, for the dirty comparison. */
function seedBody(selected) {
    const out = {};

    Object.entries(selected || {}).forEach(([code, entry]) => {
        out[code] = { note: entry?.note ?? null };
    });

    return out;
}

/* ------------------------------------------------------------------ */
/*  Region two — business-line allocation                              */
/* ------------------------------------------------------------------ */

function AllocationCard({ scenario, allocation, editable, errors, url, onDirty }) {
    const [lines, setLines] = useState(() => (allocation.lines || []).map((line) => ({ ...line })));
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(null);
    const [announced, setAnnounced] = useState(null);
    const seeded = useRef(allocation.lines);

    useEffect(() => {
        // Not after a 422: `allocations.{i}` errors index the POSTED array, so the
        // rows must stay exactly as the user left them for the messages to land on
        // the right rows (spec 03 §2).
        if (seeded.current === allocation.lines || Object.keys(errors).length > 0) {
            return;
        }

        seeded.current = allocation.lines;
        setLines((allocation.lines || []).map((line) => ({ ...line })));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [allocation.lines, errors]);

    const backup = useRegionBackup(scenario.id, 'allocations', lines, editable);

    const { cents, missing } = sumPercents(lines.map((line) => line.percent));
    const exact = isExactly100(cents) && missing === 0;

    // The ONE live announcement on this card is the valid ↔ invalid TRANSITION,
    // not the number: announcing on every keystroke of a numeric field makes the
    // tab unusable with a screen reader (spec 03 §9).
    useEffect(() => {
        if (!editable || lines.length === 0) {
            return;
        }

        setAnnounced(
            exact
                ? 'Allocation adds to 100%'
                : 'Allocation does not add to 100%; it must add to 100% before this scenario can be marked designed',
        );
    }, [exact, editable, lines.length]);

    const dirty = useMemo(
        () => JSON.stringify(postedLines(lines)) !== JSON.stringify(postedLines(allocation.lines || [])),
        [lines, allocation.lines],
    );

    useEffect(() => {
        onDirty?.(dirty);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dirty]);

    const change = (key, field, value, label) => {
        setLines((prev) =>
            prev.map((line) => (line.key === key ? { ...line, [field]: value, ...(label ? { label } : {}) } : line)),
        );
    };

    const add = (key, line, index) => {
        setLines((prev) => {
            if (line) {
                const next = [...prev];
                next.splice(index === undefined || index < 0 ? next.length : index, 0, { ...line });
                return next;
            }

            return [...prev, { key, business_unit_id: null, label: '', percent: '' }];
        });
    };

    const remove = (key) => setLines((prev) => prev.filter((line) => line.key !== key));

    const save = () => {
        setSaving(true);

        router.put(
            url,
            { allocations: postedLines(lines) },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    backup.clear();
                    setSavedAt(new Date().toISOString());
                },
            },
        );
    };

    return (
        <Card
            title="Business-line allocation"
            errorKeys={Object.keys(errors)}
            errors={errors}
            region="The allocation wasn't saved. Your causes and exposure answers are unaffected."
        >
            {backup.offer && <BackupStrip backup={backup} onRestore={(data) => setLines(data)} />}

            <AllocationEditor
                lines={lines}
                options={allocation.options}
                savedTotal={allocation.saved_total}
                truncated={Boolean(allocation.truncated)}
                readOnly={!editable}
                errors={errors}
                sumId="allocation-sum"
                onChange={change}
                onAdd={add}
                onRemove={remove}
            />

            {editable && (
                <p role="status" className="sr-only">
                    {announced}
                </p>
            )}

            {editable && (
                <SaveRow
                    label="Save allocation"
                    saving={saving}
                    savedAt={savedAt}
                    dirty={dirty}
                    onSave={save}
                    id="field-allocations"
                    describedBy="allocation-sum"
                />
            )}
        </Card>
    );
}

/**
 * The posted array — and its ORDER is the order the `allocations.{i}` error keys
 * index, which is why the list is not re-sortable in Phase 1 (spec 03 §2).
 *
 * Percents go as decimal STRINGS at the column's precision: a float that has been
 * through `json_encode` can arrive as `100` instead of `100.00`, and §A1.5
 * requires the comparison to be made as a decimal at `decimal(5,2)` (spec 01
 * §4.5). `UpdateScenarioAllocationsRequest` validates `percent` with `decimal:2`,
 * which is EXACTLY two places and not "at most two" — so `"60"` is padded to
 * `"60.00"` here, and a value with more than two places is passed through
 * untouched so the server's own message answers it rather than a client-side
 * paraphrase or a silent truncation of what the user typed.
 */
function postedLines(lines) {
    return lines.map((line) => ({
        business_unit_id: line.business_unit_id === '' ? null : line.business_unit_id,
        percent: toTwoDecimalPlaces(line.percent),
    }));
}

/** `60` → `60.00`, `33.3` → `33.30`, `33.335` → unchanged, `''` → `''`. */
function toTwoDecimalPlaces(value) {
    if (value === null || value === undefined) {
        return '';
    }

    const text = String(value).trim();

    if (text === '') {
        return '';
    }

    if (!text.includes('.')) {
        return `${text}.00`;
    }

    const fraction = text.split('.')[1] ?? '';

    return fraction.length === 1 ? `${text}0` : text;
}

/* ------------------------------------------------------------------ */
/*  Region three — exposures and risk transfer                         */
/* ------------------------------------------------------------------ */

/**
 * Two cards, ONE route and ONE button: all five columns live on `sm_scenarios`,
 * so they save together, and the button sits under the second card with a label
 * naming both (spec 03 §5.6).
 */
function ExposureCards({ scenario, exposures, yesNo, summary, editable, errors, url, notes, notesMeta, saveRef, onDirty }) {
    const [state, setState] = useState(() => seedExposures(exposures));
    const [saving, setSaving] = useState(false);
    const [savedAt, setSavedAt] = useState(null);
    const seeded = useRef(exposures);

    useEffect(() => {
        // Not after a 422 — the answers stay on screen (spec 01 §6.2).
        if (seeded.current === exposures || Object.keys(errors).length > 0) {
            return;
        }

        seeded.current = exposures;
        setState(seedExposures(exposures));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [exposures, errors]);

    const backup = useRegionBackup(scenario.id, 'exposures', state, editable);
    const dirty = useMemo(
        () => JSON.stringify(state) !== JSON.stringify(seedExposures(exposures)),
        [state, exposures],
    );

    useEffect(() => {
        onDirty?.(dirty);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dirty]);

    const set = (key, value) => setState((prev) => ({ ...prev, [key]: value }));

    const save = () => {
        setSaving(true);

        // FIVE KEYS AND NOTHING ELSE — see the file docblock on partial updates.
        router.put(
            url,
            {
                reputational_exposure: state.reputational_exposure,
                legal_compliance_exposure: state.legal_compliance_exposure,
                insurance_risk: state.insurance_risk,
                financial_risk: state.financial_risk,
                eligible_to_transfer: state.eligible_to_transfer,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    backup.clear();
                    setSavedAt(new Date().toISOString());
                },
            },
        );
    };

    const reputational = exposures.reputational_exposure || { options: [], unset_label: null };
    const legal = exposures.legal_compliance_exposure || { options: [], unset_label: null };

    return (
        <>
            <Card
                title="Exposure"
                errorKeys={Object.keys(errors).filter((key) => key.endsWith('_exposure'))}
                errors={errors}
                region="The exposure answers weren't saved. Your causes and allocation are unaffected."
            >
                {backup.offer && <BackupStrip backup={backup} onRestore={(data) => setState(data)} />}

                {editable ? (
                    <>
                        <ScaleFieldset
                            code="reputational_exposure"
                            legend="Reputational impact"
                            scale={reputational}
                            value={state.reputational_exposure}
                            error={errors.reputational_exposure}
                            onChange={(value) => set('reputational_exposure', value)}
                        />
                        <ScaleFieldset
                            code="legal_compliance_exposure"
                            legend="Legal, regulatory and compliance risk"
                            hint="Exposure to litigation, regulatory action and compliance failure arising from this scenario."
                            scale={legal}
                            value={state.legal_compliance_exposure}
                            error={errors.legal_compliance_exposure}
                            onChange={(value) => set('legal_compliance_exposure', value)}
                        />
                    </>
                ) : (
                    <>
                        <ReadScale label="Reputational impact" scale={reputational} value={exposures.reputational_exposure?.value} />
                        <ReadScale label="Legal, regulatory and compliance risk" scale={legal} value={exposures.legal_compliance_exposure?.value} />
                    </>
                )}
            </Card>

            <Card title="Risk transfer" list={!editable}>
                {editable ? (
                    <>
                        <TriState
                            code="insurance_risk"
                            legend="Insurance risk"
                            hint="Does this scenario give rise to an insurance claim or a risk-transfer question?"
                            yesNo={yesNo}
                            value={state.insurance_risk}
                            error={errors.insurance_risk}
                            onChange={(value) => set('insurance_risk', value)}
                        />
                        <TriState
                            code="financial_risk"
                            legend="Financial / market risk"
                            hint="Does this scenario carry a financial or market-risk exposure as well as an operational one?"
                            yesNo={yesNo}
                            value={state.financial_risk}
                            error={errors.financial_risk}
                            onChange={(value) => set('financial_risk', value)}
                        />
                        {/* TWO-STATE, and deliberately so (spec 03 §6.4). §A1.2
                            row 25 froze `eligible_to_transfer` NOT NULL default
                            false because Phase 2's netting formula needs a value
                            — NULL would be a third case in a two-case formula. A
                            UI offering "Not assessed" here would be collecting a
                            value it cannot store, so the honest mitigation is the
                            permanent help line saying what "No" will be taken to
                            mean. */}
                        <TwoState
                            code="eligible_to_transfer"
                            legend="Eligible to transfer"
                            hint="Could the impact of this scenario be transferred under cover the organisation holds or could buy?"
                            yesNo={yesNo}
                            value={state.eligible_to_transfer}
                            error={errors.eligible_to_transfer}
                            onChange={(value) => set('eligible_to_transfer', value)}
                        />
                        <p role="note" className="text-xs text-gray-600">
                            'Not assessed' is recorded as unanswered for insurance risk and financial/market risk. It is
                            never treated as 'No'. Eligible to transfer has no unanswered state — it defaults to No, so
                            say so in the notes if nobody has checked the cover.
                        </p>
                    </>
                ) : (
                    <>
                        <ReadFlag label="Insurance risk" value={flagWords(exposures.insurance_risk, yesNo)} />
                        <ReadFlag label="Financial / market risk" value={flagWords(exposures.financial_risk, yesNo)} />
                        <ReadFlag
                            label="Eligible to transfer"
                            value={exposures.eligible_to_transfer ? (yesNo.yes || 'Yes') : (yesNo.no || 'No')}
                            qualifier={transferQualifier(exposures, notes, notesMeta)}
                        />
                    </>
                )}

                {editable && (
                    <SaveRow
                        label="Save exposure answers"
                        saving={saving}
                        savedAt={savedAt}
                        dirty={dirty}
                        onSave={save}
                        id="field-reputational_exposure"
                        saveRef={saveRef}
                    />
                )}
            </Card>

            {!editable && summary && (
                // The read-only summary is the SERVER'S wording throughout, never
                // re-derived in the browser from a null (spec 01 §4).
                <p className="text-xs text-gray-600">
                    Recorded position: {summary.reputational_label} · {summary.legal_label}
                </p>
            )}
        </>
    );
}

/**
 * The read-only qualifier on `eligible_to_transfer` (spec 03 §6.5).
 *
 * Rendered ONLY when the whole notes log is on screen. The panel is capped at the
 * latest twenty with a Load-earlier control (spec 01 §4.4), and asserting "no note
 * records how this was determined" while two hundred notes are unloaded would be
 * a claim the screen cannot support — standard §5's defect class in a sentence
 * rather than a number.
 */
function transferQualifier(exposures, notes, notesMeta) {
    if (exposures.eligible_to_transfer) {
        return null;
    }

    if (!notesMeta || notesMeta.total !== notesMeta.shown) {
        return null;
    }

    const recorded = (notes || []).some(
        (note) =>
            ['assumption', 'challenge'].includes(note.kind) &&
            (note.body || '').toLowerCase().includes('transfer'),
    );

    return recorded ? null : '(no note records how this was determined)';
}

function seedExposures(exposures) {
    return {
        reputational_exposure: exposures?.reputational_exposure?.value ?? null,
        legal_compliance_exposure: exposures?.legal_compliance_exposure?.value ?? null,
        // NULL STAYS NULL. Coercing either to `false` here would render "not yet
        // assessed" as "No" on a governance screen — the exact defect §A1.2's
        // nullable booleans exist to prevent.
        insurance_risk: exposures?.insurance_risk ?? null,
        financial_risk: exposures?.financial_risk ?? null,
        eligible_to_transfer: Boolean(exposures?.eligible_to_transfer),
    };
}

function flagWords(value, yesNo) {
    if (value === null || value === undefined) {
        return yesNo?.unset || 'Not assessed';
    }

    return value ? (yesNo?.yes || 'Yes') : (yesNo?.no || 'No');
}

/**
 * A scale as a `fieldset` of native radios, ALL OPTIONS VISIBLE (spec 03 §6.1).
 * A six-point scale with a sentence of description per point cannot be chosen
 * from a closed dropdown: the descriptions ARE the scale, and hiding five of them
 * behind a click invites a user to pick "Moderate" because it sounds middling.
 *
 * THE FIRST RADIO IS THE UNSET STATE, posting `null`. A native radio group cannot
 * be un-selected, so without it a mis-click on "No impact" silently converts an
 * unanswered field into an affirmative assessment — the precise inversion
 * standard §5 forbids, and worse here because "No impact" is the answer that makes
 * a scenario look safe.
 */
function ScaleFieldset({ code, legend, hint, scale, value, error, onChange }) {
    const options = scale.options || [];

    return (
        <fieldset id={`field-${code}`} className="scroll-mt-24">
            <legend className="text-sm font-semibold text-gray-900">{legend}</legend>
            {hint && <p className="mt-0.5 text-xs text-gray-600">{hint}</p>}
            {error && <p className="mt-1 text-sm text-red-700">{error}</p>}

            <div className="mt-2 space-y-2">
                <RadioRow
                    name={code}
                    value=""
                    checked={value === null || value === undefined}
                    label={scale.unset_label}
                    // The unset option is a real answer with the same visual
                    // weight; dimming it would push users toward guessing.
                    onChange={() => onChange(null)}
                />
                {options.map((option) => (
                    <RadioRow
                        key={option.value}
                        name={code}
                        value={option.value}
                        checked={value === option.value}
                        label={option.label}
                        description={option.description}
                        onChange={() => onChange(option.value)}
                    />
                ))}
            </div>
        </fieldset>
    );
}

function RadioRow({ name, value, checked, label, description, onChange }) {
    const id = `${name}-${value === '' ? 'unset' : value}`;
    // WHEN `description` IS NULL NO DESCRIPTION LINE RENDERS — no placeholder and
    // no invented sentence. The legal scale ships null on every option because the
    // workbook's legal dropdown is a bare list of six labels (spec 03 §6.3).
    return (
        <div className="flex items-start gap-2">
            <input
                id={id}
                name={name}
                type="radio"
                className="form-radio mt-1"
                value={value}
                checked={checked}
                onChange={onChange}
                aria-describedby={description ? `${id}-desc` : undefined}
            />
            <label htmlFor={id} className="text-sm">
                <span className="font-medium text-gray-900">{label}</span>
                {description && (
                    <span id={`${id}-desc`} className="block text-sm text-gray-600">
                        {description}
                    </span>
                )}
            </label>
        </div>
    );
}

function TriState({ code, legend, hint, yesNo, value, error, onChange }) {
    return (
        <fieldset id={`field-${code}`} className="scroll-mt-24">
            <legend className="text-sm font-semibold text-gray-900">{legend}</legend>
            {hint && <p className="mt-0.5 text-xs text-gray-600">{hint}</p>}
            {error && <p className="mt-1 text-sm text-red-700">{error}</p>}
            <div className="mt-2 flex flex-wrap gap-4">
                <RadioRow name={code} value="yes" checked={value === true} label={yesNo?.yes || 'Yes'} onChange={() => onChange(true)} />
                <RadioRow name={code} value="no" checked={value === false} label={yesNo?.no || 'No'} onChange={() => onChange(false)} />
                <RadioRow
                    name={code}
                    value="unset"
                    checked={value === null || value === undefined}
                    label={yesNo?.unset || 'Not assessed'}
                    onChange={() => onChange(null)}
                />
            </div>
        </fieldset>
    );
}

function TwoState({ code, legend, hint, yesNo, value, error, onChange }) {
    const helpId = `${code}-help`;

    return (
        <fieldset id={`field-${code}`} className="scroll-mt-24" aria-describedby={helpId}>
            <legend className="text-sm font-semibold text-gray-900">{legend}</legend>
            {hint && <p className="mt-0.5 text-xs text-gray-600">{hint}</p>}
            {error && <p className="mt-1 text-sm text-red-700">{error}</p>}
            <div className="mt-2 flex flex-wrap gap-4">
                <RadioRow name={code} value="yes" checked={value === true} label={yesNo?.yes || 'Yes'} onChange={() => onChange(true)} />
                <RadioRow name={code} value="no" checked={value === false} label={yesNo?.no || 'No'} onChange={() => onChange(false)} />
            </div>
            {/* THE PERMANENT HELP LINE (spec 03 §6.4) — the honest mitigation
                available for a column that cannot say "unanswered". It is
                attached to the group's `aria-describedby` so a screen-reader user
                hears it as part of the group rather than as stray text. */}
            <p id={helpId} className="mt-2 text-xs text-gray-600">
                Defaults to 'No'. If nobody has checked the cover yet, record that as an assumption in the notes log —
                Phase 2's netting engine will treat 'No' as 'nothing is transferable' and compute a fully net impact.
            </p>
        </fieldset>
    );
}

function ReadScale({ label, scale, value }) {
    const option = (scale?.options || []).find((entry) => entry.value === value);

    return (
        <div>
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            {option ? (
                <p className="mt-0.5 text-sm text-gray-900">
                    <span className="font-medium">{option.label}</span>
                    {option.description && <span className="text-gray-700"> — {option.description}</span>}
                </p>
            ) : (
                // `null` renders the unset_label in gray — never the lowest grade,
                // never `—` (which would lose the distinction between
                // "unanswered" and "not rendered"), never "None" (spec 03 §6.5).
                <p className="mt-0.5 text-sm text-gray-600">{scale?.unset_label}</p>
            )}
        </div>
    );
}

function ReadFlag({ label, value, qualifier }) {
    const unset = value === 'Not assessed';

    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`mt-0.5 text-sm ${unset ? 'text-gray-600' : 'text-gray-900'}`}>
                {value}
                {qualifier && <span className="ml-1 text-xs text-gray-600">{qualifier}</span>}
            </dd>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Shared pieces                                                      */
/* ------------------------------------------------------------------ */

function Card({ title, aside, list = false, errorKeys = [], errors = {}, region, children }) {
    const Body = list ? 'dl' : 'div';
    const summaryRef = useRef(null);

    useEffect(() => {
        if (errorKeys.length > 0) {
            summaryRef.current?.focus();
            summaryRef.current?.scrollIntoView({ block: 'nearest' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errorKeys.join('|')]);

    return (
        <section className="rounded-xl border border-gray-200 bg-white">
            <div className="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <h2 className="text-sm font-semibold text-[#1A365D]">{title}</h2>
                {aside}
            </div>
            <div className="space-y-4 px-5 py-4">
                {/* The summary appears at the top of THE CARD THAT FAILED, and
                    names the region, because with three save regions a failure in
                    one leaves the other two untouched and still dirty on screen
                    (spec 01 §6.2). */}
                {errorKeys.length > 0 && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-3">
                        <h3 ref={summaryRef} tabIndex={-1} className="text-sm font-semibold text-red-800 focus:outline-none">
                            Fix {errorKeys.length} {errorKeys.length === 1 ? 'item' : 'items'}
                        </h3>
                        <ul className="mt-1 list-inside list-disc text-sm text-red-700">
                            {errorKeys.map((key) => (
                                <li key={key}>
                                    <a href={`#${anchorFor(key)}`} className="underline">
                                        {errors[key]}
                                    </a>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-2 text-xs text-red-700">{region}</p>
                    </div>
                )}

                <Body className="space-y-4">{children}</Body>
            </div>
        </section>
    );
}

/**
 * Error key → the anchor that exists for it. Cause errors key on the code
 * (`causes.{code}.note`, spec 03 §2) and the checklist renders
 * `id="field-{code}"`; allocation errors key on the POSTED INDEX
 * (`allocations.{i}.percent`), which is why the editor renders
 * `id="allocation-row-{key}"` and the summary links to the card instead.
 */
function anchorFor(key) {
    if (key.startsWith('causes.')) {
        const code = key.split('.')[1];
        return code ? `field-${code}` : 'field-causes';
    }

    if (key.startsWith('allocations')) {
        return 'field-allocations';
    }

    return `field-${key}`;
}

function SaveRow({ label, saving, savedAt, dirty, onSave, id, describedBy, saveRef }) {
    return (
        <div className="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3">
            <button
                ref={saveRef}
                type="button"
                id={id}
                onClick={onSave}
                className="btn-primary text-sm"
                disabled={saving}
                aria-busy={saving || undefined}
                aria-describedby={describedBy}
            >
                {saving ? 'Saving…' : label}
            </button>
            {/* "Last saved {hh:mm} Lagos time" ONCE A SAVE HAS SUCCEEDED IN THIS
                SESSION, and nothing before that — no "Never saved", because the
                record obviously exists (spec 01 §6.1). */}
            {savedAt && (
                <span className="text-xs text-gray-600">Last saved {backupTime(savedAt)} Lagos time</span>
            )}
            {dirty && <span className="text-xs text-gray-600">Unsaved changes</span>}
        </div>
    );
}

function BackupStrip({ backup, onRestore }) {
    return (
        <div role="status" className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-gray-800">
            We kept entries you hadn't saved
            {backupTime(backup.offer.savedAt) ? ` from ${backupTime(backup.offer.savedAt)}` : ''}.{' '}
            <button
                type="button"
                className="font-semibold text-[var(--color-primary)] underline"
                onClick={() => {
                    const data = backup.take();
                    if (data) {
                        onRestore(data);
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
    );
}
