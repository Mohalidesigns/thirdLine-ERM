import { useMemo, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import TierBadge from '@/Components/Tprm/TierBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The ruleset editor and its sandbox simulator — FR-TIER-09.
 *
 * THE SIMULATOR IS THE CONTROL ON THIS SCREEN. Moving the DATA weight from 25
 * to 30 is a change whose effect on the whole portfolio is invisible until it
 * has already happened; the simulator replays every engagement's stored answers
 * through the draft and shows the migration first, writing nothing.
 *
 * Publishing is refused while the weights do not total 100 — not because the
 * arithmetic needs it (the weighted mean divides by the actual total) but so
 * that a weight of 25 means what the administrator reading it thinks it means.
 */
export default function RulesetEditor({ ruleset, derivedFactors = [] }) {
    const [factors, setFactors] = useState(ruleset.factors ?? {});
    const [simulation, setSimulation] = useState(null);
    const [simulating, setSimulating] = useState(false);
    const { processing } = useForm({});

    const totalWeight = useMemo(
        () => Object.values(factors).reduce((sum, f) => sum + Number(f.weight ?? 0), 0),
        [factors],
    );

    const weightsValid = Math.abs(totalWeight - 100) < 0.001;

    const setWeight = (code, weight) => {
        setFactors({ ...factors, [code]: { ...factors[code], weight: Number(weight) } });
        // Any edit invalidates the last run — showing a migration table for a
        // ruleset that is no longer on screen would be worse than showing none.
        setSimulation(null);
    };

    const save = () => {
        router.put(tryRoute('tprm.rulesets.update', ruleset.id), {
            name: ruleset.name,
            notes: ruleset.notes,
            factors,
            knockouts: ruleset.knockouts,
            band_edges: ruleset.band_edges,
        }, { preserveScroll: true });
    };

    const simulate = async () => {
        setSimulating(true);
        try {
            const response = await window.axios.post(tryRoute('tprm.rulesets.simulate', ruleset.id));
            setSimulation(response.data);
        } finally {
            setSimulating(false);
        }
    };

    return (
        <AppLayout title={`Ruleset ${ruleset.version}`}>
            <Head title={`Ruleset ${ruleset.version}`} />

            <PageHeader
                title={`Ruleset ${ruleset.version}`}
                subtitle={ruleset.editable
                    ? 'Draft. Nothing here affects a score until it is published.'
                    : 'Published and immutable — every score citing this version is explained by it.'}
                actions={ruleset.editable && (
                    <div className="flex items-center gap-2">
                        <button type="button" onClick={save} disabled={processing} className="btn-secondary text-sm">
                            Save draft
                        </button>
                        <button type="button" onClick={simulate} disabled={simulating} className="btn-secondary text-sm">
                            {simulating ? 'Simulating…' : 'Run simulator'}
                        </button>
                        <button type="button"
                            disabled={!weightsValid}
                            title={weightsValid ? undefined : 'Weights must total 100 before publishing.'}
                            onClick={() => router.post(tryRoute('tprm.rulesets.publish', ruleset.id))}
                            className="btn-primary text-sm disabled:opacity-50">
                            Publish
                        </button>
                    </div>
                )}
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-gray-900">Factor weights</h3>
                            <span className={`text-sm font-semibold tabular-nums ${weightsValid ? 'text-green-700' : 'text-red-700'}`}>
                                {totalWeight} / 100
                            </span>
                        </div>

                        <div className="space-y-4">
                            {Object.entries(factors).map(([code, factor]) => (
                                <div key={code} className="border-b border-gray-100 pb-4 last:border-0 last:pb-0">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="flex-1">
                                            <p className="text-sm font-medium text-gray-900">
                                                <span className="mr-2 font-mono text-xs text-gray-400">{code}</span>
                                                {factor.label}
                                                {derivedFactors.includes(code) && (
                                                    // The `source: derived` marker: these two numbers are the
                                                    // product's defaults, not statements from the TRD, and an
                                                    // administrator editing them should know which is which.
                                                    <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
                                                        Our default, not a requirement
                                                    </span>
                                                )}
                                            </p>
                                            {factor.description && (
                                                <p className="mt-0.5 text-xs text-gray-500">{factor.description}</p>
                                            )}
                                        </div>
                                        <input
                                            type="number" min="0" max="100" step="1"
                                            value={factor.weight ?? 0}
                                            disabled={!ruleset.editable}
                                            onChange={(e) => setWeight(code, e.target.value)}
                                            className="w-20 rounded-md border-gray-300 text-right text-sm shadow-sm disabled:bg-gray-50"
                                        />
                                    </div>

                                    <details className="mt-2">
                                        <summary className="cursor-pointer text-xs text-gray-500">
                                            {(factor.options ?? []).length} options
                                        </summary>
                                        <table className="mt-2 w-full text-xs">
                                            <tbody className="divide-y divide-gray-100">
                                                {(factor.options ?? []).map((option) => (
                                                    <tr key={option.value}>
                                                        <td className="py-1 font-mono text-gray-400">{option.value}</td>
                                                        <td className="py-1 text-gray-700">{option.label}</td>
                                                        <td className="py-1 text-right tabular-nums">{option.score}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </details>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-gray-900">
                            Knockout rules ({(ruleset.knockouts ?? []).length})
                        </h3>
                        <p className="mb-3 text-xs text-gray-500">
                            A knockout sets a floor on the tier and never lowers it.
                        </p>
                        <ul className="space-y-2">
                            {(ruleset.knockouts ?? []).map((knockout) => (
                                <li key={knockout.code} className="rounded-md bg-gray-50 p-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-mono text-xs font-semibold text-gray-900">{knockout.code}</span>
                                        <TierBadge tier={knockout.floor} size="sm" />
                                    </div>
                                    <p className="mt-1 text-xs text-gray-700">{knockout.name}</p>
                                    <p className="mt-0.5 text-[11px] italic text-gray-500">{knockout.citation}</p>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                <div className="lg:col-span-1">
                    <div className="sticky top-6 card p-5">
                        <h3 className="text-sm font-semibold text-gray-900">Portfolio simulator</h3>
                        <p className="mt-1 text-xs text-gray-500">
                            Replays every engagement's stored answers through this draft. Writes nothing.
                        </p>

                        {simulation ? (
                            <>
                                <dl className="mt-4 space-y-2 text-sm">
                                    <Row label="Engagements replayed" value={simulation.assessed} />
                                    <Row label="Unchanged" value={simulation.unchanged} />
                                    <Row label="Tier raised" value={simulation.raised} tone={simulation.raised ? 'warn' : null} />
                                    <Row label="Tier lowered" value={simulation.lowered} tone={simulation.lowered ? 'bad' : null} />
                                </dl>

                                {simulation.movements?.length > 0 && (
                                    <div className="mt-4">
                                        <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            Biggest moves
                                        </h4>
                                        <ul className="mt-2 space-y-2">
                                            {simulation.movements.slice(0, 12).map((move) => (
                                                <li key={move.engagement_id} className="rounded bg-gray-50 p-2 text-xs">
                                                    <p className="font-medium text-gray-900">{move.third_party}</p>
                                                    <p className="text-gray-500">{move.reference}</p>
                                                    <div className="mt-1 flex items-center gap-1.5">
                                                        <TierBadge tier={move.before} label={move.before_label ?? 'None'} size="sm" />
                                                        <span className="text-gray-400">→</span>
                                                        <TierBadge tier={move.after} label={move.after_label} size="sm" />
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                        {simulation.truncated && (
                                            <p className="mt-2 text-[11px] text-gray-500">
                                                Showing the largest moves; the counts above cover the whole portfolio.
                                            </p>
                                        )}
                                    </div>
                                )}

                                {simulation.assessed === 0 && (
                                    <p className="mt-4 rounded bg-gray-50 p-2.5 text-xs text-gray-600">
                                        No engagement has been tiered yet, so there is nothing to replay. The
                                        simulator will have something to show once intakes have been scored.
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="mt-4 text-xs text-gray-500">
                                Run the simulator to see what this draft would do to the tiers already on the
                                register, before it does it.
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function Row({ label, value, tone }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-gray-600">{label}</dt>
            <dd className={`font-semibold tabular-nums ${
                tone === 'warn' ? 'text-amber-700' : tone === 'bad' ? 'text-red-700' : 'text-gray-900'
            }`}>{value}</dd>
        </div>
    );
}
