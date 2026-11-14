import Icon from '@thirdline/ui/Components/Icon';

/**
 * The causes checklist (spec 03 §4) — `Components/Sm/CauseChecklist.jsx`.
 *
 * BESPOKE, justified at spec 01 §0.1.2: a grouped multi-select with a per-item
 * note; `DynamicForm` has no repeater-with-children primitive.
 *
 * THE LIST IS DATA. All 23 codes, their labels, their `side`, their `group_label`,
 * their `requires_note` flag and their order arrive in `checklist` from
 * `config/sm.php` and `ScenarioCause::CHECKLIST` (addendum-3). Nothing here is a
 * JSX constant: a tenant renaming "External Salesforce / Distribution" needs no
 * front-end release.
 *
 * SELECTION IS AN OBJECT KEYED BY CODE, both in and out (spec 03 §2). An array of
 * `{code, note}` would let a deselect-and-reselect reorder the payload and turn
 * the stable `causes.{code}.note` error key into a positional one.
 *
 * `side` and `cause_group` are READ for grouping and NEVER POSTED — §A1.4 derives
 * them from `code` in the service, because a client posting them is how a row
 * arrives `side = external` with `code = people_other`.
 */
export default function CauseChecklist({ checklist, selection, readOnly, onChange }) {
    if (!checklist || checklist.length === 0) {
        // Spec 03 §8: an empty checklist is a presenter bug, and the callout is
        // how that bug becomes visible instead of reading as "no causes apply".
        return (
            <div
                role="note"
                className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <Icon name="warning" className="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    The cause checklist isn't configured for your organisation. The scenario can be saved, but it
                    can't be marked designed until it is.
                </span>
            </div>
        );
    }

    if (readOnly) {
        return <ReadOnlyCauses checklist={checklist} selection={selection} />;
    }

    // Grouped by `side` then `cause_group`, in `sort` order — spec 03 §4. The two
    // columns balance thirteen internal items in three groups against ten external
    // items in one, and no third grouping is invented to even them up.
    const columns = [
        { side: 'internal', heading: 'Internal', groups: groupBy(checklist.filter((c) => c.side === 'internal')) },
        { side: 'external', heading: 'External', groups: groupBy(checklist.filter((c) => c.side === 'external')) },
    ];

    return (
        <div className="space-y-4">
            <p className="text-sm text-gray-600">
                Tick every cause that applies. Add a note where the tick alone would be ambiguous — a validator reads
                the notes, not the ticks.
            </p>

            <div className="grid gap-4 lg:grid-cols-2">
                {columns.map((column) => (
                    <div key={column.side} className="space-y-4">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {column.heading}
                        </h3>
                        {column.groups.map((group) => (
                            <CauseGroup
                                key={group.key}
                                group={group}
                                selection={selection}
                                onChange={onChange}
                            />
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}

/** Groups in first-appearance order, which is `sort` order because the checklist already is. */
function groupBy(items) {
    const groups = [];

    for (const item of items) {
        let group = groups.find((candidate) => candidate.key === item.cause_group);

        if (!group) {
            group = { key: item.cause_group, label: item.group_label, items: [] };
            groups.push(group);
        }

        group.items.push(item);
    }

    return groups;
}

function CauseGroup({ group, selection, onChange }) {
    const selected = group.items.filter((item) => selection[item.code] !== undefined).length;
    const countId = `cause-count-${group.key}`;

    return (
        // A real fieldset/legend per group, and the live count is `aria-live="off"`
        // read on demand through `aria-describedby`: ticking six boxes in a row
        // must not produce six announcements (spec 03 §9).
        <fieldset className="rounded-lg border border-gray-200 p-3" aria-describedby={countId}>
            <legend className="flex items-center gap-2 px-1 text-sm font-semibold text-[#1A365D]">
                {group.label}
                <span id={countId} className="text-xs font-normal text-gray-600">
                    {selected} of {group.items.length} selected
                </span>
            </legend>

            <div className="mt-2 space-y-3">
                {group.items.map((item) => (
                    <CauseRow key={item.code} item={item} selection={selection} onChange={onChange} />
                ))}
            </div>
        </fieldset>
    );
}

function CauseRow({ item, selection, onChange }) {
    const entry = selection[item.code];
    const checked = entry !== undefined;
    const noteId = `field-${item.code}-note`;

    return (
        <div id={`field-${item.code}`} className="scroll-mt-24">
            <div className="flex items-start gap-2">
                <input
                    id={`cause-${item.code}`}
                    type="checkbox"
                    className="form-checkbox mt-1"
                    checked={checked}
                    onChange={(event) => onChange(item.code, event.target.checked)}
                    aria-describedby={checked ? noteId : undefined}
                />
                <label htmlFor={`cause-${item.code}`} className="text-sm text-gray-800">
                    {item.label}
                    {item.requires_note && (
                        <>
                            <span className="ml-0.5 text-red-600" aria-hidden="true">
                                *
                            </span>
                            <span className="sr-only"> (required)</span>
                        </>
                    )}
                </label>
            </div>

            {checked && (
                // In DOM order immediately after the checkbox, so Tab reaches it
                // naturally. Focus does NOT move when the box is ticked — moving
                // it would hijack a user tabbing down the list to tick four
                // causes quickly, the single most common action on this tab.
                <div className="ml-7 mt-1.5">
                    <label htmlFor={noteId} className="sr-only">
                        Note for {item.label} {item.requires_note ? '(required)' : '(optional)'}
                    </label>
                    <textarea
                        id={noteId}
                        rows={2}
                        className="form-input text-sm"
                        value={entry?.note ?? ''}
                        placeholder={item.requires_note ? undefined : 'Note (optional)'}
                        aria-required={item.requires_note || undefined}
                        onChange={(event) => onChange(item.code, true, event.target.value)}
                    />
                    {item.requires_note && (
                        <p className="mt-1 text-xs text-gray-600">
                            Say what the other cause is — 'Other' on its own tells a validator nothing.
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Read-only: SELECTED CAUSES ONLY, grouped the same way, each with its note
 * beneath (spec 03 §4.3). Unticked causes are not rendered — a read-only view
 * listing 19 things that are not true is unreadable — and a group with nothing
 * selected is omitted entirely.
 */
function ReadOnlyCauses({ checklist, selection }) {
    const codes = Object.keys(selection);

    if (codes.length === 0) {
        return <p className="text-sm text-gray-600">No cause is recorded for this scenario.</p>;
    }

    const selected = checklist.filter((item) => selection[item.code] !== undefined);
    const groups = groupBy(selected);

    return (
        <dl className="space-y-4">
            {groups.map((group) => (
                <div key={group.key}>
                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">{group.label}</dt>
                    <dd className="mt-1 space-y-2">
                        {group.items.map((item) => (
                            <div key={item.code} className="border-l-2 border-gray-200 pl-3">
                                <p className="text-sm text-gray-800">{item.label}</p>
                                {selection[item.code]?.note && (
                                    <p className="mt-0.5 whitespace-pre-line text-sm text-gray-600">
                                        {selection[item.code].note}
                                    </p>
                                )}
                            </div>
                        ))}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
