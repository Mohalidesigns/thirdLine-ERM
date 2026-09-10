import { useState } from 'react';
import TierBadge from './TierBadge';

/**
 * The score panel and its "Why this score" expander — TRD §11's right rail,
 * and the screen AC-15 is written about.
 *
 * IT RENDERS THE STORED DERIVATION AND COMPUTES NOTHING. Every number here
 * comes from the `tp_score_runs` explanation the server persisted at scoring
 * time. That is what makes two users looking at the same score at the same
 * time see identical figures: they are reading one record, not each
 * recalculating from parts.
 *
 * A missing residual renders as "Not yet scored", never as 0. An engagement
 * that Phase 5 has not reached does not have a residual risk of nought, and
 * the product's own standard (§5) forbids a figure that is absent being shown
 * as a number.
 */
function BandChip({ band, label }) {
    const tone = {
        critical: 'bg-red-100 text-red-800',
        high: 'bg-orange-100 text-orange-800',
        moderate: 'bg-amber-100 text-amber-800',
        low: 'bg-green-100 text-green-800',
    }[band] ?? 'bg-gray-100 text-gray-600';

    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}>{label ?? band}</span>;
}

/**
 * Current / Ageing / Stale — three words rather than a percentage.
 *
 * A badge reading "0.63" invites a conversation about the number; one reading
 * "Ageing" invites one about the vendor.
 */
function ConfidenceBadge({ confidence, raw }) {
    if (!confidence && (raw === null || raw === undefined)) {
        return <span className="text-sm text-gray-500">Not assessed</span>;
    }

    const badge = confidence?.badge ?? (raw >= 0.8 ? 'Current' : raw >= 0.5 ? 'Ageing' : 'Stale');
    const tone = {
        Current: 'bg-green-100 text-green-800',
        Ageing: 'bg-amber-100 text-amber-800',
        Stale: 'bg-red-100 text-red-800',
    }[badge] ?? 'bg-gray-100 text-gray-600';

    return (
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone}`} title={`DC ${raw ?? confidence?.dc}`}>
            {badge}
        </span>
    );
}

export default function ScorePanel({ engagement, derivation, score = null }) {
    const [open, setOpen] = useState(false);

    const inherent = derivation?.inherent;
    const knockouts = derivation?.knockouts_fired ?? [];
    const decidedBy = derivation?.decided_by;

    return (
        <div className="card p-5">
            <div className="flex items-start justify-between">
                <h3 className="text-sm font-semibold text-gray-900">Risk score</h3>
                {derivation?.ruleset_version && (
                    <span className="text-[11px] text-gray-500">
                        Ruleset {derivation.ruleset_version} · engine {derivation.engine_version}
                    </span>
                )}
            </div>

            <dl className="mt-4 space-y-3">
                <div className="flex items-center justify-between">
                    <dt className="text-sm text-gray-600">Inherent risk (IR)</dt>
                    <dd className="text-lg font-semibold text-gray-900">
                        {engagement.inherent_score ?? '—'}
                    </dd>
                </div>

                <div className="flex items-center justify-between">
                    <dt className="text-sm text-gray-600">Effective tier</dt>
                    <dd><TierBadge tier={engagement.effective_tier} label={engagement.effective_tier_label} /></dd>
                </div>

                <div className="flex items-center justify-between">
                    <dt className="text-sm text-gray-600">Residual risk (RR)</dt>
                    <dd className="flex items-center gap-2">
                        {score?.rr === null || score?.rr === undefined ? (
                            /* An engagement that has not been scored does not
                               have a residual risk of nought. */
                            <span className="text-sm text-gray-500">Not yet scored</span>
                        ) : (
                            <>
                                <span className="text-lg font-semibold text-gray-900">
                                    {Math.round(score.rr * 10) / 10}
                                </span>
                                <BandChip band={score.band} label={score.band_label} />
                            </>
                        )}
                    </dd>
                </div>

                {score && (
                    <div className="flex items-center justify-between">
                        <dt className="text-sm text-gray-600">Mitigation (M)</dt>
                        <dd className="text-sm tabular-nums text-gray-700">
                            {score.m === null ? '—' : `${Math.round(score.m * 1000) / 10}%`}
                        </dd>
                    </div>
                )}

                {score && (score.fu > 0 || score.su > 0) && (
                    <div className="flex items-center justify-between">
                        <dt className="text-sm text-gray-600">Uplift</dt>
                        <dd className="text-sm tabular-nums text-gray-700">
                            +{Math.round((score.fu + score.su) * 10) / 10}
                            <span className="ml-1 text-xs text-gray-500">
                                ({score.fu} findings, {score.su} signals)
                            </span>
                        </dd>
                    </div>
                )}

                <div className="flex items-center justify-between">
                    <dt className="text-sm text-gray-600">Data confidence</dt>
                    <dd>
                        <ConfidenceBadge confidence={derivation?.data_confidence} raw={score?.dc} />
                    </dd>
                </div>
            </dl>

            {derivation?.data_confidence?.may_close_review === false && (
                /* TRD §7.6's teeth. Without this the badge is decoration, and
                   the module joins every other product that prints a confident
                   number derived from stale data. */
                <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    This score may not be used to close a review or support a board assertion: the inputs
                    behind it are too old.
                    {derivation.data_confidence.weaknesses?.length > 0 && (
                        <span className="mt-1 block">{derivation.data_confidence.weaknesses[0]}</span>
                    )}
                </p>
            )}

            {derivation?.headline && (
                <p className="mt-3 text-xs leading-relaxed text-gray-600">{derivation.headline}</p>
            )}

            {decidedBy === 'knockout' && (
                <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    The tier was raised by a knockout rule, not by the weighted score.
                </p>
            )}
            {decidedBy === 'manual_override' && (
                <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    The tier was raised by a manual override.
                </p>
            )}

            {derivation && (
                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    className="mt-4 inline-flex w-full items-center justify-between rounded-md border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    aria-expanded={open}
                >
                    Why this score
                    <span className="material-symbols-outlined text-lg">
                        {open ? 'expand_less' : 'expand_more'}
                    </span>
                </button>
            )}

            {open && derivation && (
                <div className="mt-4 space-y-4 border-t border-gray-100 pt-4">
                    {derivation.arithmetic && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                The arithmetic
                            </h4>
                            {/* A reader who cannot reproduce the number will
                                not believe it, and this is a sentence anybody
                                can check with a calculator. */}
                            <p className="mt-1.5 font-mono text-xs text-gray-800">{derivation.arithmetic}</p>
                        </div>
                    )}

                    {derivation.mitigation && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Assurance held (M)
                            </h4>
                            <p className="mt-1.5 font-mono text-xs text-gray-800">
                                AC × EC × Kmax = {derivation.mitigation.arithmetic}
                            </p>
                            <p className="mt-1 text-xs text-gray-600">{derivation.mitigation.note}</p>
                        </div>
                    )}

                    {derivation.findings?.contributions?.length > 0 && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Findings (FU = {derivation.findings.total})
                            </h4>
                            <ul className="mt-2 space-y-1.5">
                                {derivation.findings.contributions.map((row, index) => (
                                    <li key={row.reference ?? index} className="rounded bg-gray-50 p-2 text-xs">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium text-gray-800">
                                                {row.reference ? `${row.reference} — ` : ''}{row.title ?? row.severity}
                                            </span>
                                            <span className="tabular-nums text-gray-900">
                                                {row.penalty > 0 ? '+' : ''}{row.penalty}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-gray-600">{row.reason}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {derivation.signals?.contributions?.length > 0 && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Monitoring signals (SU = {derivation.signals.total})
                            </h4>
                            <ul className="mt-2 space-y-1.5">
                                {derivation.signals.contributions.map((row, index) => (
                                    <li key={index} className="rounded bg-gray-50 p-2 text-xs">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium text-gray-800">{row.label}</span>
                                            <span className="tabular-nums text-gray-900">
                                                {row.penalty === null ? 'override' : `+${row.penalty}`}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-gray-600">{row.reason}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {derivation.data_confidence?.weaknesses?.length > 0 && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Why confidence is not full
                            </h4>
                            <ul className="mt-2 list-disc space-y-1 pl-4 text-xs text-gray-600">
                                {derivation.data_confidence.weaknesses.map((text) => (
                                    <li key={text}>{text}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {inherent?.factors?.length > 0 && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Weighted factors
                            </h4>
                            <table className="mt-2 w-full text-xs">
                                <thead>
                                    <tr className="text-left text-gray-500">
                                        <th className="pb-1 font-medium">Factor</th>
                                        <th className="pb-1 font-medium">Answer</th>
                                        <th className="pb-1 text-right font-medium">Score</th>
                                        <th className="pb-1 text-right font-medium">Weight</th>
                                        <th className="pb-1 text-right font-medium">Weighted</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {inherent.factors.map((factor) => (
                                        <tr key={factor.code}>
                                            <td className="py-1.5 font-medium text-gray-800">{factor.label}</td>
                                            <td className="py-1.5 text-gray-600">{factor.selected_label ?? '—'}</td>
                                            <td className="py-1.5 text-right tabular-nums text-gray-700">{factor.score}</td>
                                            <td className="py-1.5 text-right tabular-nums text-gray-500">{factor.weight}</td>
                                            <td className="py-1.5 text-right tabular-nums text-gray-900">{factor.weighted}</td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-gray-200">
                                        <td colSpan="4" className="pt-2 text-right font-medium text-gray-700">
                                            IR = Σ weighted ÷ {inherent.total_weight} × 100
                                        </td>
                                        <td className="pt-2 text-right font-semibold tabular-nums text-gray-900">
                                            {inherent.score}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}

                    {knockouts.length > 0 && (
                        <div>
                            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Knockout rules fired
                            </h4>
                            <ul className="mt-2 space-y-2">
                                {knockouts.map((knockout) => (
                                    <li key={knockout.code} className="rounded-md bg-gray-50 p-2.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-mono text-[11px] font-semibold text-gray-900">
                                                {knockout.code}
                                            </span>
                                            <TierBadge tier={knockout.floor} size="sm" />
                                        </div>
                                        <p className="mt-1 text-xs text-gray-700">{knockout.name}</p>
                                        {/* The citation is half of AC-02: a rule shown without
                                            its source cannot be checked by the person it is
                                            shown to. */}
                                        <p className="mt-1 text-[11px] italic text-gray-500">{knockout.citation}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {inherent?.unscored_answers && Object.keys(inherent.unscored_answers).length > 0 && (
                        <details className="text-xs">
                            <summary className="cursor-pointer font-medium text-gray-600">
                                Answers captured but not scored ({Object.keys(inherent.unscored_answers).length})
                            </summary>
                            <ul className="mt-2 space-y-1.5">
                                {Object.entries(inherent.unscored_answers).map(([key, item]) => (
                                    <li key={key} className="text-gray-600">
                                        <span className="font-mono font-semibold">{key}</span> — {item.reason}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}

                    {inherent?.warnings?.length > 0 && (
                        <div className="rounded-md bg-red-50 p-2.5">
                            <h4 className="text-xs font-semibold text-red-900">Warnings</h4>
                            <ul className="mt-1 list-inside list-disc text-[11px] text-red-800">
                                {inherent.warnings.map((warning, i) => <li key={i}>{warning}</li>)}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
