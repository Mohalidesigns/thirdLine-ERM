import { useRef, useState } from 'react';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import Icon from '@thirdline/ui/Components/Icon';
import { centsToPercentString, isExactly100, sumPercents } from './format';

/**
 * The business-line allocation editor (spec 03 §5) — `Components/Sm/AllocationEditor.jsx`.
 *
 * BESPOKE, justified at spec 01 §0.1.3: a repeater carrying a live invariant
 * (percent sum = 100). `DynamicForm` has no repeater primitive.
 *
 * THE INVARIANT IS ADVISORY ON THIS TAB AND BLOCKING AT THE TRANSITION (spec 03
 * §5.2). §A1.5 enforces the sum at `in_design → designed` "and not on every draft
 * save — a draft legitimately sums to 60% mid-entry". So the sum line shows the
 * mismatch in red and the Save button stays a normal, enabled control: a screen
 * that refused to save 60% would be enforcing a stricter rule than the server
 * has, which is its own kind of lie.
 *
 * THE SUM IS SCALED-INTEGER ARITHMETIC, never float (`./format`, spec 03 §5.2).
 * Percents arrive as decimal strings at `decimal(5,2)`; adding them as floats
 * gives `33.33 + 33.33 + 33.34 = 100.00000000000001`.
 *
 * NO EQUAL-SPLIT BUTTON (spec 03 §5.5): splitting three rows into
 * 33.33/33.33/33.34 would assert a distribution nobody stated, and it would need
 * a rounding decision no UI should make silently.
 *
 * THE LIST IS NOT RE-SORTABLE (spec 03 §2): `allocations.{i}` error keys index
 * the POSTED array, so a drag-to-reorder would silently move errors onto the
 * wrong rows and no test in this repository could catch it.
 */
export default function AllocationEditor({
    lines,
    options,
    savedTotal,
    truncated = false,
    readOnly = false,
    errors = {},
    sumId = 'allocation-sum',
    onChange,
    onAdd,
    onRemove,
}) {
    // The undo affordance (spec 03 §5.3). Removing a row destroys no data until
    // Save, so it is immediate and reversible with no confirmation modal — a
    // modal for a reversible one-field action would be the third dialog in a
    // workshop session and users would start clicking through them.
    const [removed, setRemoved] = useState(null);
    const nextKey = useRef(0);

    if (readOnly) {
        return <ReadOnlyAllocation lines={lines} savedTotal={savedTotal} />;
    }

    if (!options || options.length === 0) {
        // Spec 03 §8: §A1.5's unique key and NOT NULL `business_unit_id` mean
        // there is no allocation without a unit. No dead link — the shell sends
        // no admin URL for this in Phase 1, so the sentence stands alone.
        return (
            <div
                role="note"
                className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                <span>No business units are available to allocate to, so this scenario can't be allocated.</span>
            </div>
        );
    }

    const { cents, missing } = sumPercents(lines.map((line) => line.percent));
    const exact = isExactly100(cents);
    const sumText = centsToPercentString(cents);

    // Options already used by another row are removed from the list: §A1.5's
    // unique key `sm_allocations_scenario_unit_unique` makes a duplicate a
    // database error, and the UI should make it impossible rather than merely
    // rejected (spec 03 §5.1).
    const used = new Set(lines.map((line) => line.business_unit_id).filter((id) => id !== null && id !== ''));
    const exhausted = used.size >= options.length;

    const addRow = () => {
        nextKey.current += 1;
        setRemoved(null);
        onAdd(`new-${nextKey.current}`);
    };

    const removeRow = (key) => {
        const line = lines.find((candidate) => candidate.key === key);
        const index = lines.findIndex((candidate) => candidate.key === key);

        setRemoved({ line, index });
        onRemove(key);
    };

    const undo = () => {
        if (!removed) {
            return;
        }

        onAdd(removed.line.key, removed.line, removed.index);
        setRemoved(null);
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-600">
                How much of this scenario lands on each business line. Must add to 100% before the scenario can be
                marked designed.
            </p>

            {truncated && (
                <p className="text-xs text-gray-600">Too many business lines to list — search instead.</p>
            )}

            {errors.allocations && (
                <p id="allocation-sum-error" className="flex items-start gap-1.5 text-sm text-red-700">
                    <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                    {errors.allocations}
                </p>
            )}

            {lines.length === 0 ? (
                // Spec 03 §5.4 — and it never reads "0%". A rate over nothing is
                // undefined, not zero: "0% allocated" claims the scenario has
                // been allocated and nothing landed anywhere, which is a
                // different and false statement.
                <div className="rounded-lg border border-dashed border-gray-300 p-6 text-center">
                    <p className="text-sm font-semibold text-gray-800">Not allocated</p>
                    <p className="mx-auto mt-1 max-w-md text-sm text-gray-600">
                        No business line carries this scenario yet. Add the lines it would hit and the share of the
                        impact each would carry.
                    </p>
                    {!exhausted && (
                        <button type="button" onClick={addRow} className="btn-secondary mt-4 text-sm">
                            <Icon name="add" className="h-4 w-4" />
                            <span className="ml-1">Add a business line</span>
                        </button>
                    )}
                </div>
            ) : (
                <div className="space-y-3">
                    {lines.map((line, index) => (
                        <AllocationRow
                            key={line.key}
                            line={line}
                            index={index}
                            options={options.filter((option) => option.id === line.business_unit_id || !used.has(option.id))}
                            errors={errors}
                            onChange={onChange}
                            onRemove={removeRow}
                        />
                    ))}

                    {exhausted ? (
                        <p className="text-xs text-gray-600">Every business line is already allocated.</p>
                    ) : (
                        <button type="button" onClick={addRow} className="btn-secondary text-sm">
                            <Icon name="add" className="h-4 w-4" />
                            <span className="ml-1">Add a business line</span>
                        </button>
                    )}
                </div>
            )}

            {removed?.line && (
                // A `role="status"` line replaces the row for the rest of the
                // session. Undo restores it at its ORIGINAL POSITION with its
                // percent, and focus returns to the restored row's percent input
                // (spec 03 §5.3, §9).
                <p role="status" className="text-sm text-gray-700">
                    Removed {removed.line.label || 'that line'}
                    {removed.line.percent ? ` (${removed.line.percent}%)` : ''}.{' '}
                    <button type="button" onClick={undo} className="font-semibold text-[var(--color-primary)] underline">
                        Undo
                    </button>
                </p>
            )}

            {/* The card's most important element (spec 03 §5.2). NOT an
                `aria-live` region — announcing on every keystroke of a numeric
                field makes the tab unusable. The Save button's
                `aria-describedby` points here instead, and the one live
                announcement is the valid ↔ invalid transition, debounced to
                blur, which the parent owns. */}
            <div id={sumId} className="text-sm">
                {lines.length === 0 ? (
                    <span className="text-gray-600">Not allocated</span>
                ) : exact && missing === 0 ? (
                    <span className="flex items-center gap-1.5 font-semibold text-green-700">
                        <Icon name="check_circle" className="h-4 w-4" />
                        Adds to 100%
                    </span>
                ) : (
                    <span className="space-y-1">
                        <span className="flex items-center gap-1.5 font-semibold text-red-700">
                            <Icon name="warning" className="h-4 w-4" />
                            Adds to {sumText}%
                            {missing > 0 && (
                                <>
                                    {' '}
                                    — {missing} {missing === 1 ? 'row' : 'rows'} still need a percentage
                                </>
                            )}
                        </span>
                        <span className="block text-red-700">Business-line allocation must add to 100%.</span>
                    </span>
                )}
            </div>
        </div>
    );
}

function AllocationRow({ line, index, options, errors, onChange, onRemove }) {
    const [query, setQuery] = useState('');
    const selected = options.find((option) => option.id === line.business_unit_id) || null;
    const percentId = `allocation-row-${line.key}-percent`;
    const pickerId = `allocation-row-${line.key}-unit`;
    const percentError = errors[`allocations.${index}.percent`];
    const unitError = errors[`allocations.${index}.business_unit_id`];

    const filtered = query.trim() === ''
        ? options
        : options.filter((option) => option.name.toLowerCase().includes(query.trim().toLowerCase()));

    return (
        // `role="group"` labelled with the line and its share, updated on blur
        // rather than per keystroke (spec 03 §9).
        <div
            id={`allocation-row-${line.key}`}
            role="group"
            aria-label={`${line.label || 'Unassigned line'}, ${line.percent || 'no percentage'}%`}
            className="scroll-mt-24 grid gap-2 sm:grid-cols-[minmax(0,1fr)_8rem_auto] sm:items-start"
        >
            <div>
                <label htmlFor={pickerId} className="sr-only">
                    Business line {index + 1}
                </label>
                <Combobox
                    // Uncontrolled `value` with `displayValue` over local state —
                    // the pattern `Kri/Settings/Contacts.jsx` proves works. A
                    // `displayValue` derived from the selected object is
                    // re-applied on every render and wipes the query as it is
                    // typed, which silently breaks the picker's filter.
                    value={null}
                    onChange={(option) => {
                        if (!option) {
                            return;
                        }

                        onChange(line.key, 'business_unit_id', option.id, option.name);
                        setQuery('');
                    }}
                    immediate
                >
                    <ComboboxInput
                        id={pickerId}
                        className="form-input text-sm"
                        displayValue={() => (query === '' ? (selected?.name ?? '') : query)}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search business lines…"
                        aria-invalid={unitError ? 'true' : undefined}
                        aria-describedby={unitError ? `${pickerId}-error` : undefined}
                    />
                    <ComboboxOptions className="mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg empty:hidden">
                        {filtered.map((option) => (
                            <ComboboxOption
                                key={option.id}
                                value={option}
                                className="cursor-pointer px-3 py-2 text-sm data-[focus]:bg-blue-50"
                            >
                                {option.name}
                            </ComboboxOption>
                        ))}
                    </ComboboxOptions>
                </Combobox>
                {unitError && (
                    <p id={`${pickerId}-error`} className="mt-1 text-sm text-red-700">
                        {unitError}
                    </p>
                )}
            </div>

            <div>
                <label htmlFor={percentId} className="sr-only">
                    Share of {line.label || 'this business line'} (%)
                </label>
                <div className="relative">
                    <input
                        id={percentId}
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        inputMode="decimal"
                        // Right-aligned, tabular figures, and the `%` sits OUTSIDE
                        // the input — never a placeholder, which is not a label
                        // (spec 03 §5.1). ≥16px on mobile so iOS does not zoom.
                        className="form-input pr-7 text-right text-base tabular-nums"
                        value={line.percent ?? ''}
                        onChange={(event) => onChange(line.key, 'percent', event.target.value)}
                        aria-invalid={percentError ? 'true' : undefined}
                        aria-describedby={percentError ? `${percentId}-error` : undefined}
                    />
                    <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-gray-600" aria-hidden="true">
                        %
                    </span>
                </div>
                {percentError && (
                    <p id={`${percentId}-error`} className="mt-1 text-sm text-red-700">
                        {percentError}
                    </p>
                )}
            </div>

            <button
                type="button"
                onClick={() => onRemove(line.key)}
                aria-label={`Remove ${line.label || 'this business line'}`}
                className="justify-self-start p-2 text-gray-500 hover:text-red-700 sm:justify-self-end"
            >
                <Icon name="delete" className="h-5 w-5" />
                <span className="sr-only">Remove</span>
            </button>
        </div>
    );
}

/**
 * Read-only: a two-column `dl` of line → share with the total beneath, or the
 * same two words "Not allocated" when `saved_total` is null (spec 03 §8).
 *
 * The total shown is `saved_total`, THE SERVER'S SUM of the saved lines — never
 * re-added in the browser, and never "0%" for an absence.
 */
function ReadOnlyAllocation({ lines, savedTotal }) {
    if (!lines || lines.length === 0) {
        return <p className="text-sm text-gray-600">Not allocated</p>;
    }

    return (
        <dl className="space-y-1">
            {lines.map((line) => (
                <div key={line.key} className="flex justify-between gap-4 text-sm">
                    <dt className="text-gray-700">{line.label}</dt>
                    <dd className="tabular-nums text-gray-900">
                        {line.percent === null || line.percent === undefined || line.percent === '' ? '—' : `${line.percent}%`}
                    </dd>
                </div>
            ))}
            <div className="mt-2 flex justify-between gap-4 border-t border-gray-200 pt-2 text-sm font-semibold">
                <dt>Total</dt>
                <dd className="tabular-nums">
                    {savedTotal === null || savedTotal === undefined ? 'Not allocated' : `${savedTotal}%`}
                </dd>
            </div>
        </dl>
    );
}
