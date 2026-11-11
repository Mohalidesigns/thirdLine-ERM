import Icon from '@thirdline/ui/Components/Icon';
import { formatLagosInstant } from './format';
import { resolveSources } from './signoffSources';

/**
 * The sign-off panel (spec 02 §6) — read-only in Phase 1.
 *
 * Sign-off rows are written by the shell's sign-off modal (spec 01 §5.6), never
 * here; this panel is where a reader checks who approved the scenario and whether
 * anybody independent did.
 *
 * ONLY `is_current` ROWS RENDER. §A1.6 puts no unique key on
 * `(sm_scenario_id, source)`, so a revalidation APPENDS new rows and the current
 * sign-off for a source is the latest by `signed_at`. `is_current` is the
 * SERVER'S determination and the client never sorts or picks "latest" itself —
 * with `signed_at` nullable and only non-null rows counting toward the lifecycle,
 * duplicating that logic in JS is how two surfaces come to disagree about who
 * signed (spec 01 §4.6). Superseded rows have no Phase-1 surface at all; they
 * live in `risk_audit_trail` until Phase 3's Sign-off & audit tab.
 *
 * NAMES AND ROLES ARE SNAPSHOTS, NOT JOINS (spec 01 §4.7). `signatory_name` and
 * `role` are NOT NULL columns, so the panel still reads correctly after a
 * signatory leaves the bank. `role` is a free-text capacity — never a Spatie
 * platform role name, because renaming one "would silently falsify every
 * historical sign-off storing it".
 */
export default function SignoffPanel({ signoffs, sources }) {
    const current = (signoffs || []).filter((row) => row.is_current);

    if (current.length === 0) {
        return (
            <div className="py-2 text-center">
                <p className="text-sm font-semibold text-gray-800">No sign-off recorded</p>
                <p className="mx-auto mt-1 max-w-md text-sm text-gray-500">
                    Sign-off sources are captured when the scenario is signed off. Until then this scenario carries no
                    approval.
                </p>
            </div>
        );
    }

    // One group per source in `Signoff::SOURCES` order. The server already sorted
    // `signoffs` by source, so this only buckets — it does not re-order.
    const bucketed = sourceBuckets(sources, signoffs, current);

    return (
        <div className="space-y-4">
            {bucketed.map((bucket) => (
                <div key={bucket.source}>
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">{bucket.label}</h3>
                    {bucket.rows.length === 0 ? (
                        <p className="mt-1 text-sm text-gray-500">— no sign-off recorded —</p>
                    ) : (
                        bucket.rows.map((row) => <SignoffRow key={row.id} row={row} />)
                    )}
                </div>
            ))}

            {/* Where "approved with conditions" went. A signer looking for it
                will not find it, so the panel says where it lives: §A1.6 refused
                a third decision value because "a third value nothing reads is how
                a workflow acquires a state nobody can leave". */}
            <p className="text-xs text-gray-600">
                Conditions and caveats are recorded in the notes log below, as an assumption — so they are written down
                rather than implied by a decision.
            </p>
        </div>
    );
}

function SignoffRow({ row }) {
    const returned = row.decision === 'returned_for_rework';
    // `signed_at` is nullable (§A1.6, standard §13) and only non-null rows count
    // toward the lifecycle. A null row renders muted with the words "Recorded,
    // not signed", so a reader never mistakes it for an approval — and never as
    // "—, not counted", which reads like a rendering artefact.
    const signed = row.signed_at ? formatLagosInstant(row.signed_at) : null;

    return (
        <dl
            className={`mt-1 space-y-1 border-l-4 py-2 pl-3 ${returned ? 'border-red-600' : 'border-gray-200'}`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <dt className="sr-only">Signatory</dt>
                <dd className="text-sm font-medium text-gray-900">{row.signatory_name}</dd>
                <dt className="sr-only">Capacity</dt>
                <dd className="text-sm text-gray-600">{row.role}</dd>
                {row.is_independent_challenge && (
                    // The chip NEVER appears as "Not independent" — the absence of
                    // a flag is not an assertion about the signer (spec 02 §6).
                    <span className="badge badge-status-active gap-1">
                        <Icon name="gavel" className="h-3.5 w-3.5" />
                        Independent challenge
                    </span>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-x-2 text-sm">
                <dt className="sr-only">Signed</dt>
                <dd className={signed ? 'text-gray-600' : 'text-gray-500 italic'}>
                    {signed || 'Recorded, not signed'}
                </dd>
                <dt className="sr-only">Decision</dt>
                <dd className={returned ? 'font-semibold text-red-700' : 'font-semibold text-gray-900'}>
                    {row.decision_label}
                </dd>
            </div>

            {row.comment && (
                <div>
                    <dt className="sr-only">Comment</dt>
                    <dd className="whitespace-pre-line text-sm text-gray-700">{row.comment}</dd>
                </div>
            )}
        </dl>
    );
}

/**
 * One bucket per source in `Signoff::SOURCES` order, including a source that has
 * never signed — the sketch in spec 02 §6 renders "Source 3 — no sign-off
 * recorded —" rather than omitting it, so a reader can see that a source is
 * outstanding rather than that the panel ran out of rows.
 *
 * The order and the labels come from the server's `signoffSources` vocabulary
 * prop; `./signoffSources` keeps a mirror only as a fallback for a payload built
 * before that prop existed.
 */
function sourceBuckets(fromServer, allRows, currentRows) {
    // `resolveSources` already prefers a `source_label` snapshot from a row over
    // the fallback wording, so the bucket label is simply what it returns.
    return resolveSources(fromServer ?? null, allRows || []).map((source) => ({
        source: source.value,
        label: source.label,
        rows: currentRows.filter((row) => row.source === source.value),
    }));
}
