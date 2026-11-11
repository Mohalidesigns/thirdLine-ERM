/**
 * The sign-off source vocabulary, and a stopgap.
 *
 * [backend defect] `ScenarioWorkspacePresenter::present()`
 * (app/Presenters/Sm/ScenarioWorkspacePresenter.php:78-131) sends `signoffs` rows
 * but NOT the source vocabulary behind them. Spec 01 §5.6.2 requires the sign-off
 * modal to render "up to three rows, Source 1 / Source 2 / Source 3
 * (`Signoff::SOURCES`)", and spec 02 §6 requires the panel to render one group
 * per source in `SOURCES` order — including "Source 3 — no sign-off recorded —"
 * for a source that has never signed. Neither is derivable from `signoffs`, which
 * is EMPTY for the normal pre-sign-off case the modal exists for.
 *
 * `config('sm.sources')` already holds the vocabulary (config/sm.php:314) and
 * `StoreScenarioSignoffsRequest` validates against `array_keys(Signoff::SOURCES)`,
 * so the fix is one presenter line — `'signoffSources' => config('sm.sources')` —
 * and the same argument applies to the two decision labels, which spec 01 §5.6.2
 * also names as data.
 *
 * Until that prop exists, this module is the ONE place the vocabulary is written
 * down on the client. It mirrors `App\Models\Sm\Signoff::SOURCES` exactly
 * (app/Models/Sm/Signoff.php:60-64), and `resolveSources()` prefers the server's
 * labels wherever a row carries one, so the day the prop lands this file becomes
 * dead code rather than a second authority.
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
