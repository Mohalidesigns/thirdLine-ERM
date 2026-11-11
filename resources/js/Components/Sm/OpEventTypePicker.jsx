import { useEffect, useState } from 'react';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import Icon from '@thirdline/ui/Components/Icon';

/**
 * The OP event-type picker (spec 02 §5.4, spec 04 §2) — shared by the create form
 * and the Overview & Governance tab, because both render the same field with the
 * same option source and the same copy.
 *
 * A COMBOBOX, NOT A `<select>`: OP7's label is 158 characters (§A1.9) and 17
 * options of that length are unreadable in a closed dropdown.
 *
 * NO CLIENT-SIDE PARSING AND NO CONCATENATION. `risk_taxonomies` has no `code`
 * column — the OP number is already the first thing in `name` — so `name` is
 * rendered verbatim. An earlier draft of spec 02 concatenated a separate `code`
 * and would have rendered "OP7 OP7 …".
 *
 * GROUPING IS DATA, NOT A GUESS. `basel_level_1` is extracted SERVER-SIDE from
 * the node's `description` (§A1.9) and arrives as a prop; when a tenant has
 * edited that description and it no longer parses, the prop is null and the
 * picker falls back to grouping by `parent`, and then to a flat list. The UI must
 * work with all three, and never parses `description` in the browser.
 */
export default function OpEventTypePicker({
    options,
    value,
    onChange,
    // The INPUT's id — the field code, like every other control on the tab
    // (`#name`, `#currency`, `#fx_rate_id`, `#risk_id`). It must NOT be
    // `field-risk_taxonomy_node_id`: that id belongs to the WRAPPER the call
    // sites render, which is the `#field-{code}` anchor the readiness strip and
    // the 422 summary link to and `goToField` scrolls to. Two elements sharing it
    // made `document.getElementById` resolve to the wrapper, so the `<label for>`
    // below named a `<div>` and the combobox still had no accessible name.
    id = 'risk_taxonomy_node_id',
    // The accessible name of the combobox input. Both call sites render their own
    // VISIBLE label as a `<span className="form-label">` outside this component,
    // which is styled like a label and read like one but is not associated with
    // anything — so the input's only name was its placeholder, "Search the Basel
    // OP event types", which is an instruction (spec 01 §0.3: "Placeholder text is
    // never the label"). The default matches the visible text at both call sites;
    // pass it explicitly if that ever diverges.
    label = 'OP event type',
    error,
    required = false,
    describedBy,
}) {
    const [query, setQuery] = useState('');
    const selected = options && options.length > 0 ? options.find((option) => option.id === value) || null : null;

    // The closed field shows the selected node's full `name`. Seeding from the
    // prop rather than from Headless UI's own value is what lets `value={null}`
    // below work: the precedent this follows (`Kri/Settings/Contacts.jsx`) keeps
    // the Combobox uncontrolled and drives the visible text from local state,
    // because a `displayValue` derived from a selected OBJECT is re-applied on
    // every render and wipes what the user is typing.
    const [display, setDisplay] = useState(selected?.name ?? '');

    useEffect(() => {
        setDisplay(selected?.name ?? '');
        setQuery('');
    }, [value]);

    if (!options || options.length === 0) {
        // Spec 02 §5.4: a LIVE go-live gap, not a hypothetical one. §A1.9 records
        // that no taxonomy seeder ran before Phase 1 and that tenants are
        // provisioned outside this repository, so until provisioning calls
        // `OpEventTypeCatalogue::ensureFor()` a new tenant's picker is empty —
        // and the callout is how that stays visible rather than silent. It does
        // NOT block save: the field is optional until the `designed` gate.
        return (
            <div
                role="note"
                className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    No Basel OP event types are configured for your organisation. The scenario can be saved without
                    one, but it can't be marked designed until the taxonomy is seeded.
                </span>
            </div>
        );
    }

    const term = query.trim().toLowerCase();
    const filtered = term === '' ? options : options.filter((option) => option.name.toLowerCase().includes(term));
    const groups = groupOptions(filtered);
    // The whole verbatim string goes in the closed field's `aria-describedby` as
    // well as its `title`, so a screen-reader user gets all 158 characters even
    // when the eye does not (spec 02 §5.4).
    const fullId = `${id}-full`;

    return (
        <div>
            {/* `sr-only` rather than a second visible label: the call site already
                shows the words. What was missing is the ASSOCIATION, and a
                `<label for>` supplies it — the pattern `AllocationEditor.jsx` uses
                for its business-line combobox. */}
            <label htmlFor={id} className="sr-only">
                {label}
            </label>
            <Combobox
                // `value={null}` and a `displayValue` over LOCAL STATE, following
                // the working precedent in `Kri/Settings/Contacts.jsx`. Deriving
                // `displayValue` from the selected object instead makes Headless
                // UI re-apply it on every render and wipe the query as it is
                // typed — which is why filtering produced no options at all.
                value={null}
                onChange={(option) => {
                    if (!option) {
                        return;
                    }

                    onChange(option.id);
                    setDisplay(option.name);
                    setQuery('');
                }}
                immediate
            >
                <div className="relative">
                    <ComboboxInput
                        id={id}
                        className="form-input pr-9 text-sm"
                        displayValue={() => (query === '' ? display : query)}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search the Basel OP event types"
                        autoComplete="off"
                        title={selected ? selected.name : undefined}
                        aria-required={required || undefined}
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={[describedBy, selected ? fullId : null, error ? `${id}-error` : null]
                            .filter(Boolean)
                            .join(' ') || undefined}
                    />
                    {selected && (
                        // A real button in the tab order, not a mouse-only glyph:
                        // the field is optional before the gate, and a picker
                        // with no empty state traps the user in a choice they did
                        // not mean to make (spec 02 §5.4, §10).
                        <button
                            type="button"
                            onClick={() => onChange(null)}
                            className="absolute inset-y-0 right-2 my-auto h-6 rounded px-1 text-xs font-semibold text-gray-500 hover:text-gray-800"
                        >
                            Clear
                            <span className="sr-only"> the OP event type</span>
                        </button>
                    )}
                </div>

                <ComboboxOptions className="mt-1 max-h-80 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg empty:hidden">
                    {groups.map((group) => (
                        <div key={group.key}>
                            {group.label && (
                                // `role="presentation"` visually, with the group
                                // name announced INSIDE each option's accessible
                                // name, so a screen-reader user hears "OP5, Loss
                                // of key staff/talent management, Employment
                                // practices and workplace safety" rather than
                                // losing the Basel grouping sighted users get
                                // (spec 02 §10).
                                <div
                                    role="presentation"
                                    className="sticky top-0 bg-gray-50 px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    {group.label}
                                </div>
                            )}
                            {group.options.map((option) => (
                                <ComboboxOption
                                    key={option.id}
                                    value={option}
                                    className="cursor-pointer px-3 py-2 text-sm data-[focus]:bg-blue-50"
                                >
                                    {/* NO TRUNCATION INSIDE THE OPEN LIST: hiding words in a
                                        list the user is choosing from is how a wrong mapping
                                        gets picked, and these labels are near-identical in
                                        their first few words (OP16 vs OP17). */}
                                    <span className="block whitespace-normal break-words">
                                        {option.name}
                                        {group.label && <span className="sr-only">, {group.label}</span>}
                                    </span>
                                </ComboboxOption>
                            ))}
                        </div>
                    ))}
                    {filtered.length === 0 && (
                        <p className="px-3 py-2 text-sm text-gray-600">No OP event type matches that search.</p>
                    )}
                </ComboboxOptions>
            </Combobox>

            <span id={fullId} className="sr-only">
                {selected ? selected.name : 'No OP event type chosen.'}
            </span>

            {error && (
                <p id={`${id}-error`} className="mt-1 text-sm text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * Groups by `basel_level_1`, then by `parent.name`, then not at all — in that
 * order of preference, per option. Both come from the server; the client only
 * picks whichever is present and preserves the payload's order within a group.
 */
function groupOptions(options) {
    const groups = [];

    for (const option of options) {
        const label = option.basel_level_1 || option.parent?.name || '';
        const key = label || '__flat';
        let group = groups.find((candidate) => candidate.key === key);

        if (!group) {
            group = { key, label, options: [] };
            groups.push(group);
        }

        group.options.push(option);
    }

    // A single unnamed group is a flat list; render it without a heading rather
    // than with an empty one.
    return groups;
}
