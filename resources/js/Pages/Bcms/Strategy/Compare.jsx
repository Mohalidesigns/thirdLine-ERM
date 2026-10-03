import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The options for one process, side by side — the ISO 22331 decision.
 *
 * THE COMPARISON IS THE DELIVERABLE. "Relocate: ₦42m, 4 hours" beside "Remote
 * working: ₦6m, 12 hours" is what an executive committee decides on. Two cards
 * on two screens is not a comparison, it is two facts.
 *
 * THE REQUIRED RTO IS DRAWN THROUGH THE TABLE as a row of its own, so an option
 * that misses it is visibly missing something rather than merely carrying a
 * larger number than its neighbour.
 */
export default function Compare({ process = {}, required = null, options = [], can = {} }) {
    const money = (minor, currency) => (minor == null
        ? '—'
        : `${currency === 'NGN' ? '₦' : `${currency ?? ''} `}${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`);

    const resourceKeys = ['people', 'technology', 'facilities', 'information', 'suppliers', 'funding'];

    // "1.00 hours" is what a decimal column reads like and not what anybody
    // says. The figure is the same; the sentence is one somebody can quote.
    const hours = (value) => {
        const n = Number(value);

        return `${n} ${n === 1 ? 'hour' : 'hours'}`;
    };

    return (
        <AppLayout title={`Strategy options — ${process.code}`}>
            <Head title={`Strategy options — ${process.code}`} />

            <PageHeader
                title={`${process.code} — ${process.name}`}
                subtitle={required?.rto_hours != null
                    ? `The approved BIA requires this process back within ${hours(required.rto_hours)}.`
                    : 'This process has no approved business impact assessment, so there is no agreed recovery time to measure an option against.'}
                actions={(
                    <Link href={tryRoute('bcms.strategy.index')} className="btn-secondary text-sm">
                        Back to the register
                    </Link>
                )}
            />

            {options.length === 0 ? (
                <div className="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">
                    No strategy options have been proposed for this process.
                </div>
            ) : (
                // A side-by-side option comparison, not a row listing — a layout
                // matrix (attribute × option) kept border-separate like the other
                // matrices in this module, so its row headers and dense figures
                // are not squeezed by data-table's listing padding.
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="w-full border-separate border-spacing-0 text-sm [&_th]:px-4 [&_th]:py-3 [&_th]:text-left [&_th]:font-semibold [&_th]:text-gray-700 [&_td]:px-4 [&_td]:py-3 [&_thead_th]:bg-gray-50 [&_thead_th]:text-xs [&_thead_th]:uppercase [&_thead_th]:tracking-wider [&_thead_th]:text-gray-500">
                        <thead>
                            <tr>
                                <th>&nbsp;</th>
                                {options.map((o) => (
                                    <th key={o.id}>
                                        {o.is_selected && (
                                            <span className="mr-1 rounded bg-gray-900 px-1.5 py-0.5 text-[10px] text-white">Selected</span>
                                        )}
                                        {o.strategy_label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            <tr>
                                <th>Title</th>
                                {options.map((o) => <td key={o.id}>{o.title ?? '—'}</td>)}
                            </tr>
                            <tr>
                                <th>Cost</th>
                                {options.map((o) => (
                                    <td key={o.id} className="font-mono">{money(o.cost_estimate_minor, o.currency)}</td>
                                ))}
                            </tr>
                            <tr className="bg-gray-50">
                                <th>Required RTO</th>
                                {options.map((o) => (
                                    <td key={o.id} className="font-mono text-gray-600">
                                        {o.rto_required_hours != null ? `${o.rto_required_hours}h` : '—'}
                                    </td>
                                ))}
                            </tr>
                            <tr>
                                <th>Achievable RTO</th>
                                {options.map((o) => (
                                    <td
                                        key={o.id}
                                        className={`font-mono ${o.meets_requirement === false ? 'text-red-700' : o.meets_requirement === true ? 'text-green-700' : ''}`}
                                    >
                                        {o.rto_achievable_hours != null ? `${Number(o.rto_achievable_hours)}h` : '—'}
                                        {o.meets_requirement === false && <span className="ml-2 text-xs">misses it</span>}
                                    </td>
                                ))}
                            </tr>
                            <tr>
                                <th>Gap at assessment</th>
                                {options.map((o) => (
                                    <td key={o.id} className="font-mono text-xs text-gray-500">
                                        {o.gap_vs_required_hours == null
                                            ? '—'
                                            : `${o.gap_vs_required_hours > 0 ? '+' : ''}${o.gap_vs_required_hours}h`}
                                    </td>
                                ))}
                            </tr>
                            <tr>
                                <th>What it involves</th>
                                {options.map((o) => (
                                    <td key={o.id} className="text-xs text-gray-600">{o.description ?? '—'}</td>
                                ))}
                            </tr>
                            {resourceKeys.map((key) => (
                                <tr key={key}>
                                    <th className="capitalize">{key}</th>
                                    {options.map((o) => {
                                        const value = o.resource_requirements?.[key];

                                        return (
                                            <td key={o.id} className="text-xs text-gray-600">
                                                {Array.isArray(value) ? value.join(', ') : (value ?? '—')}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                            <tr>
                                <th>Rationale</th>
                                {options.map((o) => (
                                    <td key={o.id} className="text-xs italic text-gray-600">{o.selection_rationale ?? '—'}</td>
                                ))}
                            </tr>
                            <tr>
                                <th>Status</th>
                                {options.map((o) => (
                                    <td key={o.id} className="text-xs">
                                        <span className={`rounded px-2 py-1 ${o.approval_status === 'approved'
                                            ? 'bg-green-100 text-green-800'
                                            : o.approval_status === 'rejected'
                                                ? 'bg-red-100 text-red-800'
                                                : 'bg-gray-100 text-gray-700'}`}>
                                            {o.approval_status}
                                        </span>
                                    </td>
                                ))}
                            </tr>
                            {can.approve && (
                                <tr>
                                    <th>&nbsp;</th>
                                    {options.map((o) => (
                                        <td key={o.id} className="space-x-3 text-xs">
                                            {!o.is_selected && (
                                                <button
                                                    type="button"
                                                    className="text-blue-700 hover:underline"
                                                    onClick={() => router.post(tryRoute('bcms.strategy.select', o.uuid), {}, { preserveScroll: true })}
                                                >
                                                    Select
                                                </button>
                                            )}
                                            {o.is_selected && o.approval_status !== 'approved' && (
                                                <button
                                                    type="button"
                                                    className="text-green-700 hover:underline"
                                                    onClick={() => router.post(tryRoute('bcms.strategy.approve', o.uuid), {}, { preserveScroll: true })}
                                                >
                                                    Approve
                                                </button>
                                            )}
                                            {can.manage && (
                                                <button
                                                    type="button"
                                                    className="text-gray-600 hover:underline"
                                                    onClick={() => router.post(tryRoute('bcms.strategy.reassess', o.uuid), {}, { preserveScroll: true })}
                                                >
                                                    Reassess
                                                </button>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
