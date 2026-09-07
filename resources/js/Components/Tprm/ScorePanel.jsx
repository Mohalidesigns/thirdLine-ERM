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
export default function ScorePanel({ engagement, derivation }) {
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
                    <dd className="text-sm text-gray-500">
                        {engagement.residual_score ?? 'Not yet scored'}
                    </dd>
                </div>

                <div className="flex items-center justify-between">
                    <dt className="text-sm text-gray-600">Data confidence</dt>
                    <dd className="text-sm text-gray-500">
                        {engagement.data_confidence ?? 'Not assessed'}
                    </dd>
                </div>
            </dl>

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
