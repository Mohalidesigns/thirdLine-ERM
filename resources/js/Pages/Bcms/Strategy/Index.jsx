import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import CostRtoScatter from '@/Components/Bcms/CostRtoScatter';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The continuity strategy register — ISO 22331.
 *
 * THE GAP HEADLINE IS FIRST AND IS A SENTENCE. "Six of your eleven Tier-1
 * processes cannot recover as fast as you have decided they must, by 34 hours
 * in total" is what a continuity manager takes to a budget meeting. A grid of
 * cards is what they work from afterwards.
 *
 * OPTIONS ARE SHOWN PER PROCESS, NOT AS ONE FLAT LIST. The clause 8.3 decision
 * is "which of these three, for this process", and a flat register of ninety
 * strategies has thrown that structure away.
 */
export default function Index({ cards = [], gap = {}, scatter = {}, strategy_types = [], max_tier, can = {} }) {
    const [openProcess, setOpenProcess] = useState(null);
    const summary = gap.summary ?? {};

    const propose = useForm({
        process_id: '', strategy_type: 'recover', title: '', description: '',
        cost_estimate_minor: '', currency: 'NGN', rto_achievable_hours: '', selection_rationale: '',
    });

    const submit = (e) => {
        e.preventDefault();
        propose.post(tryRoute('bcms.strategy.store'), {
            preserveScroll: true,
            onSuccess: () => { propose.reset(); setOpenProcess(null); },
        });
    };

    const money = (minor, currency) => (minor == null
        ? '—'
        : `${currency === 'NGN' ? '₦' : `${currency ?? ''} `}${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);

    const tierFilter = (tier) => router.get(
        tryRoute('bcms.strategy.index'),
        { tier: tier || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    return (
        <AppLayout title="Continuity strategy">
            <Head title="Continuity strategy" />

            <PageHeader
                title="Continuity strategy"
                subtitle="What each process will actually do when it cannot run normally, what that costs, and how fast it recovers."
                actions={(
                    <Link href={tryRoute('bcms.strategy.gap')} className="btn-secondary text-sm">
                        Gap analysis
                    </Link>
                )}
            />

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                {summary.processes === 0 ? (
                    <p className="text-sm text-gray-600">
                        No processes are in scope at this criticality tier, so there is nothing to build a strategy for yet.
                    </p>
                ) : (
                    <p className="text-sm text-gray-800">
                        <span className="font-semibold">{summary.with_gap ?? 0}</span> of{' '}
                        <span className="font-semibold">{summary.processes ?? 0}</span> processes cannot recover as fast
                        as their approved BIA requires
                        {summary.total_shortfall_hours != null && (
                            <>, a total shortfall of <span className="font-semibold">{summary.total_shortfall_hours} hours</span></>
                        )}
                        .{' '}
                        {summary.unprotected > 0 && (
                            <span className="text-red-700">
                                {summary.unprotected} have no selected strategy at all.
                            </span>
                        )}{' '}
                        {summary.stale_assessments > 0 && (
                            <span className="text-amber-700">
                                {summary.stale_assessments} strategies were assessed against a BIA that has since been superseded.
                            </span>
                        )}
                    </p>
                )}

                <div className="mt-4 flex items-center gap-2 text-xs">
                    <span className="text-gray-500">Criticality tier:</span>
                    {[1, 2, 3, null].map((t) => (
                        <button
                            key={String(t)}
                            type="button"
                            onClick={() => tierFilter(t)}
                            className={`rounded px-2 py-1 ${String(max_tier) === String(t ?? '') || (t === null && max_tier == null)
                                ? 'bg-gray-900 text-white' : 'bg-gray-100 text-gray-700'}`}
                        >
                            {t === null ? 'All' : `Tier ${t} and above`}
                        </button>
                    ))}
                </div>
            </div>

            {scatter.points?.length > 0 && (
                <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                    <h2 className="mb-1 text-sm font-semibold text-gray-900">Cost against achievable recovery time</h2>
                    <p className="mb-4 text-xs text-gray-500">
                        Green meets the required recovery time, red does not, grey has no approved BIA to compare against.
                        The larger, outlined points are the selected strategies.
                    </p>
                    <CostRtoScatter points={scatter.points} />
                    {scatter.unplottable_count > 0 && (
                        <p className="mt-3 text-xs text-amber-700">
                            {scatter.unplottable_count} options are not plotted because they have no cost estimate or no
                            achievable recovery time recorded.
                        </p>
                    )}
                </div>
            )}

            <div className="space-y-4">
                {cards.length === 0 && (
                    <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                        No processes at this tier.
                    </div>
                )}

                {cards.map((card) => (
                    <div key={card.process_id} className="rounded-lg border border-gray-200 bg-white">
                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 p-5">
                            <div>
                                <p className="text-sm font-semibold text-gray-900">
                                    <Link href={tryRoute('bcms.strategy.show', card.process_uuid)} className="hover:underline">
                                        {card.code} — {card.name}
                                    </Link>
                                </p>
                                <p className="mt-1 text-xs text-gray-500">
                                    {card.business_unit ?? 'Organisation-level'}
                                    {card.tier != null && ` · Tier ${card.tier}`}
                                    {card.is_critical_service && ' · Regulatory critical service'}
                                </p>
                            </div>
                            <div className="text-right text-xs">
                                <p className="text-gray-500">Required RTO</p>
                                <p className="font-mono text-lg text-gray-900">
                                    {card.rto_required_hours != null ? `${card.rto_required_hours}h` : '—'}
                                </p>
                                {card.rto_required_hours == null && (
                                    <p className="text-amber-700">No approved BIA</p>
                                )}
                            </div>
                        </div>

                        <div className="divide-y divide-gray-100">
                            {card.options.length === 0 && (
                                <p className="p-5 text-sm text-red-700">
                                    No strategy options have been proposed for this process.
                                </p>
                            )}

                            {card.options.map((option) => (
                                <div key={option.id} className="flex flex-wrap items-center justify-between gap-3 p-5">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-gray-900">
                                            {option.is_selected && (
                                                <span className="mr-2 rounded bg-gray-900 px-1.5 py-0.5 text-[10px] uppercase text-white">
                                                    Selected
                                                </span>
                                            )}
                                            <span className="font-medium">{option.strategy_label}</span>
                                            {option.title && <span className="text-gray-600"> — {option.title}</span>}
                                        </p>
                                        {option.description && (
                                            <p className="mt-1 text-xs text-gray-600">{option.description}</p>
                                        )}
                                        {option.selection_rationale && (
                                            <p className="mt-1 text-xs italic text-gray-500">{option.selection_rationale}</p>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-6 text-xs">
                                        <div className="text-right">
                                            <p className="text-gray-500">Cost</p>
                                            <p className="font-mono text-gray-900">{money(option.cost_estimate_minor, option.currency)}</p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-gray-500">Achievable</p>
                                            <p className="font-mono text-gray-900">
                                                {option.rto_achievable_hours != null ? `${option.rto_achievable_hours}h` : '—'}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-gray-500">Gap</p>
                                            <p className={`font-mono ${option.gap_vs_required_hours > 0 ? 'text-red-700' : 'text-gray-900'}`}>
                                                {option.gap_vs_required_hours == null
                                                    ? '—'
                                                    : `${option.gap_vs_required_hours > 0 ? '+' : ''}${option.gap_vs_required_hours}h`}
                                            </p>
                                        </div>
                                        <span className={`rounded px-2 py-1 ${option.approval_status === 'approved'
                                            ? 'bg-green-100 text-green-800'
                                            : option.approval_status === 'rejected'
                                                ? 'bg-red-100 text-red-800'
                                                : 'bg-gray-100 text-gray-700'}`}>
                                            {option.approval_status}
                                        </span>

                                        {can.approve && !option.is_selected && (
                                            <button
                                                type="button"
                                                className="text-blue-700 hover:underline"
                                                onClick={() => router.post(tryRoute('bcms.strategy.select', option.uuid), {}, { preserveScroll: true })}
                                            >
                                                Select
                                            </button>
                                        )}
                                        {can.approve && option.is_selected && option.approval_status !== 'approved' && (
                                            <button
                                                type="button"
                                                className="text-green-700 hover:underline"
                                                onClick={() => router.post(tryRoute('bcms.strategy.approve', option.uuid), {}, { preserveScroll: true })}
                                            >
                                                Approve
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {can.manage && (
                            <div className="border-t border-gray-100 p-4">
                                {openProcess === card.process_id ? (
                                    <form onSubmit={submit} className="space-y-3">
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <label className="text-xs">
                                                <span className="text-gray-600">Strategy</span>
                                                <select
                                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                                    value={propose.data.strategy_type}
                                                    onChange={(e) => propose.setData('strategy_type', e.target.value)}
                                                >
                                                    {strategy_types.map((t) => (
                                                        <option key={t.value} value={t.value}>{t.label}</option>
                                                    ))}
                                                </select>
                                            </label>
                                            <label className="text-xs">
                                                <span className="text-gray-600">Cost (whole naira)</span>
                                                <input
                                                    type="number" min="0"
                                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                                    value={propose.data.cost_estimate_minor === '' ? '' : propose.data.cost_estimate_minor / 100}
                                                    onChange={(e) => propose.setData('cost_estimate_minor', e.target.value === '' ? '' : Math.round(Number(e.target.value) * 100))}
                                                />
                                            </label>
                                            <label className="text-xs">
                                                <span className="text-gray-600">Achievable RTO (hours)</span>
                                                <input
                                                    type="number" step="0.25" min="0"
                                                    className="mt-1 w-full rounded border-gray-300 text-sm"
                                                    value={propose.data.rto_achievable_hours}
                                                    onChange={(e) => propose.setData('rto_achievable_hours', e.target.value)}
                                                />
                                            </label>
                                        </div>
                                        <input
                                            type="text" placeholder="Short title"
                                            className="w-full rounded border-gray-300 text-sm"
                                            value={propose.data.title}
                                            onChange={(e) => propose.setData('title', e.target.value)}
                                        />
                                        <textarea
                                            rows={2} placeholder="What this strategy actually involves"
                                            className="w-full rounded border-gray-300 text-sm"
                                            value={propose.data.description}
                                            onChange={(e) => propose.setData('description', e.target.value)}
                                        />
                                        {strategy_types.find((t) => t.value === propose.data.strategy_type)?.requires_rationale && (
                                            <textarea
                                                rows={2}
                                                placeholder="Why this option — required for accepting the outage or relying on a peer"
                                                className="w-full rounded border-amber-300 text-sm"
                                                value={propose.data.selection_rationale}
                                                onChange={(e) => propose.setData('selection_rationale', e.target.value)}
                                            />
                                        )}
                                        <div className="flex gap-2">
                                            <button type="submit" className="btn-primary text-xs" disabled={propose.processing}>
                                                Add option
                                            </button>
                                            <button type="button" className="btn-secondary text-xs" onClick={() => setOpenProcess(null)}>
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-xs text-blue-700 hover:underline"
                                        onClick={() => { propose.setData('process_id', card.process_id); setOpenProcess(card.process_id); }}
                                    >
                                        Propose a strategy option
                                    </button>
                                )}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
