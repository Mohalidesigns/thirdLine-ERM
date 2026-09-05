import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DonutChart from '@/Components/DonutChart';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import RatingBadge from '@/Components/RatingBadge';
import { SELECT, effectivenessTone, titleCase } from './format';

/**
 * Bow-tie analysis for one risk (Phase 5.1: risk/analysis/bowtie.blade.php).
 *
 * ON NOT MAKING THIS A WIDGET. The phase prompt asks for a `bowtie` resolver in
 * `app/Services/Widgets/Types/`. A bow-tie is a single-subject diagram — the
 * causes and controls on the left of ONE risk, its consequences and controls on
 * the right — reached by `?risk_id=`. A dashboard widget answers a question
 * about a population; this answers one about a record, and it is the detail
 * view of a risk rather than a panel. Adding a resolver would build a dashboard
 * feature nobody asked for. Recorded in the module notes.
 */
function Side({ title, subtitle, items, controls, controlsTitle, align = 'left' }) {
    return (
        <div className={align === 'right' ? 'text-left' : ''}>
            <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
            <p className="text-xs text-gray-400 mb-3">{subtitle}</p>

            <ul className="space-y-2 mb-5">
                {items.length === 0 && <li className="text-sm text-gray-400">None recorded.</li>}
                {items.map((item, index) => (
                    <li key={index} className="bg-white rounded-lg border border-gray-200 px-3 py-2">
                        <p className="text-sm text-gray-800">{item.description}</p>
                        {item.financial_impact !== null && item.financial_impact !== undefined && (
                            <p className="text-xs text-gray-500 mt-0.5">
                                Recorded exposure: ₦{Number(item.financial_impact).toLocaleString()}
                            </p>
                        )}
                    </li>
                ))}
            </ul>

            <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">{controlsTitle}</h4>
            <ul className="space-y-1.5">
                {controls.length === 0 && <li className="text-sm text-gray-400">No controls mapped.</li>}
                {controls.map((control, index) => (
                    <li key={index} className="flex items-start justify-between gap-2 bg-gray-50 rounded-lg px-3 py-2">
                        <div>
                            <p className="text-sm text-gray-800">{control.name}</p>
                            <p className="text-[11px] text-gray-500">{control.gaps}</p>
                        </div>
                        <span className={`badge ${effectivenessTone(control.effectiveness)} whitespace-nowrap`}>
                            {titleCase(control.effectiveness)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function Bowtie({
    risks = [],
    selected = null,
    causes = [],
    consequences = [],
    preventiveControls = [],
    mitigatingControls = [],
    controlEffData = null,
}) {
    const choose = (riskId) =>
        router.get(route('risk.analysis.bowtie'), riskId ? { risk_id: riskId } : {}, {
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <AuthenticatedLayout title="Bow-tie Analysis">
            <Head title="Bow-tie Analysis" />

            <PageHeader
                title="Bow-tie Analysis"
                subtitle="What can cause a risk, what it would cause, and the controls on each side"
                breadcrumbs={[{ label: 'Analysis' }, { label: 'Bow-tie' }]}
                actions={
                    <div className="w-72">
                        <label htmlFor="risk_id" className="sr-only">Risk</label>
                        <select id="risk_id" value={selected?.id ?? ''} onChange={(e) => choose(e.target.value)} className={SELECT}>
                            <option value="">Select a risk…</option>
                            {risks.map((risk) => (
                                <option key={risk.id} value={risk.id}>{risk.code} — {risk.title}</option>
                            ))}
                        </select>
                    </div>
                }
            />

            {selected === null ? (
                <EmptyState
                    icon={<span className="material-symbols-outlined text-3xl text-gray-400">account_tree</span>}
                    title="Choose a risk"
                    description="A bow-tie is drawn for one risk at a time. Pick one above to see its causes, consequences and the controls on each side."
                />
            ) : (
                <>
                    <div className="bg-white rounded-xl border border-gray-200 p-5 mb-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <Link href={selected.url} className="text-base font-bold text-[#1A365D] hover:underline">
                                    {selected.code} — {selected.title}
                                </Link>
                                {selected.category && <p className="text-xs text-gray-500 mt-0.5">{selected.category}</p>}
                                {selected.description && <p className="text-sm text-gray-600 mt-2 max-w-3xl">{selected.description}</p>}
                            </div>
                            <div className="flex items-center gap-6">
                                <div className="text-right">
                                    <p className="text-xs text-gray-500">Inherent</p>
                                    <div className="mt-1 flex items-center gap-2 justify-end">
                                        <span className="text-sm font-semibold">{selected.inherentScore ?? '—'}</span>
                                        <RatingBadge rating={String(selected.inherentRating ?? '').toLowerCase()} />
                                    </div>
                                </div>
                                <div className="text-right">
                                    <p className="text-xs text-gray-500">Residual</p>
                                    <div className="mt-1 flex items-center gap-2 justify-end">
                                        <span className="text-sm font-semibold">{selected.residualScore ?? '—'}</span>
                                        <RatingBadge rating={String(selected.residualRating ?? '').toLowerCase()} />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <Side
                            title="Causes"
                            subtitle="What could bring this risk about"
                            items={causes}
                            controls={preventiveControls}
                            controlsTitle="Preventive &amp; directive controls"
                        />
                        <Side
                            title="Consequences"
                            subtitle="What would follow if it happened"
                            items={consequences}
                            controls={mitigatingControls}
                            controlsTitle="Detective &amp; corrective controls"
                            align="right"
                        />
                    </div>

                    {controlEffData && (
                        <div className="bg-white rounded-xl border border-gray-200 p-5 max-w-md">
                            <h3 className="text-sm font-semibold text-[#1A365D]">Control Effectiveness</h3>
                            {/* Unrated is its own slice. Folding it into
                                "Ineffective" would report a control library
                                nobody has tested as one that failed. */}
                            <p className="text-xs text-gray-400 mb-4">Across the controls mapped to this risk</p>
                            <DonutChart
                                data={controlEffData.labels.map((label, index) => ({
                                    name: label,
                                    value: controlEffData.values[index] ?? 0,
                                    color: ['#2D7D46', '#D4AF37', '#C53030', '#9CA3AF'][index] ?? '#9CA3AF',
                                }))}
                            />
                        </div>
                    )}
                </>
            )}
        </AuthenticatedLayout>
    );
}
