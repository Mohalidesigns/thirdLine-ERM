/**
 * Shared by `Emns/Index.jsx` (the console composer) and `Incidents/
 * CrisisRoom.jsx` (the roll-call / SitRep / stakeholder-comms composers) —
 * ADR 0024 item B's one audience picker, so the two screens cannot offer two
 * different ideas of "who does this reach".
 *
 * EMITS THE EXACT LEAF SHAPE `App\Support\Bcms\AudienceRule` VALIDATES.
 * `org_node` and `call_tree` carry a singular `id`; `site` carries a plural
 * `ids`; `role` carries `names`. Getting singular/plural wrong here is a
 * 422 the operator would meet after choosing, not a shape mismatch caught
 * in review — see `ValidAudienceRule`'s own leaf-by-leaf `assertValid()`.
 *
 * ONE KIND AT A TIME. Combinators (`all_of`/`any_of`/`none_of`) are in the
 * grammar but not offered here — the demo and the crisis-room composers both
 * need "send to this one group", never a boolean expression over several.
 *
 * "EXERCISE PARTICIPANTS" ONLY APPEARS AS A CHOICE WHEN AN OCCURRENCE HAS
 * BEEN SELECTED (the `occurrence` prop) — `occurrence_participants` resolves
 * by `occurrence_id` (`AudienceResolver::byOccurrence()`), so offering it
 * with nothing to resolve against would be a kind with no possible value.
 */
import { useId } from 'react';

const KIND_OPTIONS = [
    { value: 'call_tree', label: 'Call tree' },
    { value: 'site', label: 'Site(s)' },
    { value: 'org_node', label: 'Org unit' },
    { value: 'role', label: 'Role(s)' },
];

function toggle(list, item) {
    return list.includes(item) ? list.filter((x) => x !== item) : [...list, item];
}

/** The plain-language line under the picker — never a re-statement of the raw rule JSON. */
function summarize(rule, options, occurrence) {
    if (!rule) return null;

    switch (rule.type) {
        case 'call_tree': {
            const t = (options.call_trees ?? []).find((c) => c.id === Number(rule.id));
            return t ? `Everyone on the ${t.name} call tree.` : null;
        }
        case 'site': {
            const names = (rule.ids ?? [])
                .map((id) => (options.sites ?? []).find((s) => s.id === Number(id))?.name)
                .filter(Boolean);
            return names.length ? `Everyone at ${joinList(names)}.` : null;
        }
        case 'org_node': {
            const n = (options.org_nodes ?? []).find((o) => o.id === Number(rule.id));
            if (!n) return null;
            return `Everyone in ${n.name}${rule.include_descendants ? ', and every sub-unit beneath it' : ''}.`;
        }
        case 'role': {
            const names = rule.names ?? [];
            return names.length ? `Everyone holding: ${joinList(names)}.` : null;
        }
        case 'occurrence_participants':
            return `Everyone recorded as a participant on ${occurrence?.name ?? 'the selected exercise'}.`;
        default:
            return null;
    }
}

function joinList(items) {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;
}

/**
 * @param {object} props
 * @param {{call_trees: object[], sites: object[], org_nodes: object[], roles: string[]}} props.options
 * @param {object|null} props.value - the current `audience_rule` leaf, or null
 * @param {(rule: object|null) => void} props.onChange
 * @param {string} [props.error] - `errors.audience_rule`
 * @param {{id: number, name: string}|null} [props.occurrence] - the currently chosen exercise occurrence, if any
 * @param {boolean} [props.required] - shows a required marker; enforcement is the caller's (the backend accepts a null audience)
 * @param {string} [props.legend]
 * @param {string} [props.idPrefix]
 */
export default function AudiencePicker({
    options = {}, value = null, onChange, error, occurrence = null,
    required = false, legend = 'Audience', idPrefix,
}) {
    const generated = useId();
    const prefix = idPrefix ?? generated;
    const callTrees = options.call_trees ?? [];
    const sites = options.sites ?? [];
    const orgNodes = options.org_nodes ?? [];
    const roles = options.roles ?? [];

    const kind = value?.type ?? '';
    const errorId = `${prefix}-audience-error`;
    const summaryId = `${prefix}-audience-summary`;
    const summary = summarize(value, { call_trees: callTrees, sites, org_nodes: orgNodes, roles }, occurrence);
    const describedBy = error ? errorId : summary ? summaryId : undefined;
    // The VALUE control (call tree / sites / org unit / roles), not only the
    // kind select above it, is what `errors.audience_rule` is actually about
    // once a kind is chosen — a numeric-id or non-empty-list failure is a
    // fact about the value, and a screen-reader user tabbed onto that
    // control must hear it there, not only on the control two tab-stops back.
    const valueDescribedBy = error ? errorId : undefined;
    const valueInvalid = error ? 'true' : undefined;

    const kindOptions = occurrence
        ? [...KIND_OPTIONS, { value: 'occurrence_participants', label: 'Exercise participants' }]
        : KIND_OPTIONS;

    const setKind = (next) => {
        switch (next) {
            case 'call_tree': onChange({ type: 'call_tree', id: '' }); break;
            case 'site': onChange({ type: 'site', ids: [] }); break;
            case 'org_node': onChange({ type: 'org_node', id: '', include_descendants: false }); break;
            case 'role': onChange({ type: 'role', names: [] }); break;
            case 'occurrence_participants':
                onChange(occurrence ? { type: 'occurrence_participants', id: occurrence.id } : null);
                break;
            default: onChange(null);
        }
    };

    return (
        <fieldset className="space-y-2">
            <legend className="form-label">
                {legend}
                {required && (
                    <>
                        <span className="form-required" aria-hidden="true">*</span>
                        <span className="sr-only"> (required)</span>
                    </>
                )}
            </legend>

            <div>
                <label htmlFor={`${prefix}-kind`} className="sr-only">Audience kind</label>
                <select id={`${prefix}-kind`} className="form-select" value={kind}
                    aria-describedby={describedBy}
                    onChange={(e) => setKind(e.target.value)}>
                    <option value="">Choose who this reaches…</option>
                    {kindOptions.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
                </select>
            </div>

            {kind === 'call_tree' && (
                <div>
                    <label htmlFor={`${prefix}-call-tree`} className="sr-only">Call tree</label>
                    <select id={`${prefix}-call-tree`} className="form-select"
                        value={value?.id ?? ''}
                        aria-describedby={valueDescribedBy} aria-invalid={valueInvalid}
                        onChange={(e) => onChange({ ...value, id: e.target.value === '' ? '' : Number(e.target.value) })}>
                        <option value="">Select a call tree</option>
                        {callTrees.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    {callTrees.length === 0 && <p className="mt-1 text-xs text-slate-500">No call trees are set up yet.</p>}
                </div>
            )}

            {kind === 'site' && (
                <fieldset className="rounded border border-slate-200 p-2" aria-describedby={valueDescribedBy}>
                    <legend className="px-1 text-xs font-medium text-slate-600">Sites</legend>
                    <div className="max-h-40 space-y-1 overflow-y-auto">
                        {sites.map((s) => (
                            <label key={s.id} className="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" className="form-checkbox"
                                    checked={(value?.ids ?? []).includes(s.id)}
                                    aria-invalid={valueInvalid}
                                    onChange={() => onChange({ ...value, ids: toggle(value?.ids ?? [], s.id) })} />
                                {s.name}{s.code ? ` (${s.code})` : ''}
                            </label>
                        ))}
                        {sites.length === 0 && <p className="text-xs text-slate-500">No active sites.</p>}
                    </div>
                </fieldset>
            )}

            {kind === 'org_node' && (
                <div className="space-y-2">
                    <label htmlFor={`${prefix}-org-node`} className="sr-only">Org unit</label>
                    <select id={`${prefix}-org-node`} className="form-select"
                        value={value?.id ?? ''}
                        aria-describedby={valueDescribedBy} aria-invalid={valueInvalid}
                        onChange={(e) => onChange({ ...value, id: e.target.value === '' ? '' : Number(e.target.value) })}>
                        <option value="">Select an org unit</option>
                        {orgNodes.map((o) => <option key={o.id} value={o.id}>{o.name}{o.code ? ` (${o.code})` : ''}</option>)}
                    </select>
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" className="form-checkbox"
                            checked={!!value?.include_descendants}
                            onChange={(e) => onChange({ ...value, include_descendants: e.target.checked })} />
                        Include every sub-unit beneath it
                    </label>
                </div>
            )}

            {kind === 'role' && (
                <fieldset className="rounded border border-slate-200 p-2" aria-describedby={valueDescribedBy}>
                    <legend className="px-1 text-xs font-medium text-slate-600">Roles</legend>
                    <div className="max-h-40 space-y-1 overflow-y-auto">
                        {roles.map((r) => (
                            <label key={r} className="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" className="form-checkbox"
                                    checked={(value?.names ?? []).includes(r)}
                                    aria-invalid={valueInvalid}
                                    onChange={() => onChange({ ...value, names: toggle(value?.names ?? [], r) })} />
                                {r}
                            </label>
                        ))}
                        {roles.length === 0 && <p className="text-xs text-slate-500">No roles are held by anybody in this tenant.</p>}
                    </div>
                </fieldset>
            )}

            {kind === 'occurrence_participants' && (
                <p className="text-xs text-slate-600">
                    Everyone recorded as a participant on {occurrence?.name ?? 'the selected exercise'}.
                </p>
            )}

            {summary && !error && <p id={summaryId} className="text-xs text-slate-600">{summary}</p>}
            {error && <p id={errorId} role="alert" className="form-error">{error}</p>}
        </fieldset>
    );
}
