/**
 * The sign-off source and decision vocabulary, and the fallback behind it.
 *
 * THE PROPS NOW EXIST. `ScenarioWorkspacePresenter::present()` sends both
 * `'signoffSources' => $this->options((array) config('sm.sources', Signoff::SOURCES))`
 * and `'signoffDecisions' => $this->options((array) config('sm.decision_labels',
 * Scenario::DECISION_LABELS))`, so on a normal workspace render the server's
 * vocabulary is what reaches the screen and the mirrors below are never read.
 * An earlier revision of this docblock recorded their absence as a backend
 * defect; that was true when it was written and is stale now.
 *
 * THE FALLBACK STAYS, deliberately. Spec 01 §5.6.2 requires the sign-off modal to
 * render "up to three rows, Source 1 / Source 2 / Source 3 (`Signoff::SOURCES`)",
 * and spec 02 §6 requires the panel to render one group per source in `SOURCES`
 * order — including "Source 3 — no sign-off recorded —" for a source that has
 * never signed. Neither is derivable from `signoffs`, which is EMPTY for the
 * normal pre-sign-off case the modal exists for. So a payload that arrives
 * without the vocabulary — a partial `only: [...]` reload that did not ask for it,
 * a cached page, a prop renamed in a future presenter — must still render three
 * rows rather than an empty modal that looks like a working one.
 *
 * It mirrors `App\Models\Sm\Signoff::SOURCES` exactly and
 * `App\Models\Sm\Scenario::DECISION_LABELS`, and both resolvers PREFER the
 * server's labels wherever they are present, so this file is a floor and never a
 * second authority: a tenant that renames "Source 1" in `config/sm.php` sees its
 * own wording.
 */

const FALLBACK_SOURCES = [
    { value: 'source_1', label: 'Source 1' },
    { value: 'source_2', label: 'Source 2' },
    { value: 'source_3', label: 'Source 3' },
];

/**
 * The source vocabulary in `Signoff::SOURCES` order.
 *
 * @param {Array<{value: string, label: string}>|null|undefined} fromServer the
 *        prop the presenter does not send yet; used verbatim when present.
 * @param {Array<{source: string, source_label: string}>} signoffs rows, whose
 *        `source_label` snapshots are the server's own wording and win over the
 *        fallback for any source they name.
 */
export function resolveSources(fromServer, signoffs = []) {
    const base = Array.isArray(fromServer) && fromServer.length > 0 ? fromServer : FALLBACK_SOURCES;

    return base.map((entry) => {
        const row = signoffs.find((candidate) => candidate.source === entry.value);

        return row?.source_label ? { ...entry, label: row.source_label } : entry;
    });
}

/** The two decisions — `Scenario::DECISIONS`, exactly two (§A1.6 refused a third). */
const FALLBACK_DECISIONS = [
    { value: 'approved', label: 'Approved' },
    { value: 'returned_for_rework', label: 'Returned for rework' },
];

/**
 * The decision vocabulary, preferring any `decision_label` the server sent on a
 * row so a tenant that renames one is not contradicted by the client.
 */
export function resolveDecisions(fromServer, signoffs = []) {
    const base = Array.isArray(fromServer) && fromServer.length > 0 ? fromServer : FALLBACK_DECISIONS;

    return base.map((entry) => {
        const row = signoffs.find((candidate) => candidate.decision === entry.value);

        return row?.decision_label ? { ...entry, label: row.decision_label } : entry;
    });
}

export { FALLBACK_SOURCES, FALLBACK_DECISIONS };
