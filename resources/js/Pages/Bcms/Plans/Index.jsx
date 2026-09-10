import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The plan library.
 *
 * IT OPENS ON WHAT IS WRONG. "Four of your eleven plans are past review and two
 * have drifted from the BIA" is a work queue; an alphabetical list of plans is
 * a folder. Everything else on this screen is navigation.
 *
 * "PLANS CURRENT" IS BLANK WHEN THERE ARE NO PLANS, not 100%. An organisation
 * with no continuity plans has not achieved perfect currency, and a tile that
 * says it has is one somebody will paste into a board paper.
 */
export default function Index({
    plans = [], currency = {}, types = [], templates = [], filters = {}, scope_note = null, can = {},
}) {
    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState([]);

    const create = useForm({
        plan_type: 'bcp', title: '', template_key: '',
        business_unit_id: '', site_id: '', owner_id: '', review_frequency_months: 12,
    });

    const filter = (key, value) => router.get(
        tryRoute('bcms.plans.index'),
        { ...filters, [key]: value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );

    const templatesForType = templates.filter((t) => t.plan_type === create.data.plan_type);

    const cycle = useForm({ plan_ids: [], review_frequency_months: '' });

    const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    const applyCycle = (e) => {
        e.preventDefault();
        cycle.transform((data) => ({ ...data, plan_ids: selected }))
            .post(tryRoute('bcms.plans.review-cycle'), {
                preserveScroll: true,
                onSuccess: () => { setSelected([]); cycle.reset(); },
            });
    };

    return (
        <AppLayout title="Plans">
            <Head title="Plans" />

            <PageHeader
                title="Continuity plans"
                subtitle="Assembled from live data, versioned, approved and distributed — including offline."
                actions={(
                    <div className="flex gap-2">
                        <Link href={tryRoute('bcms.plans.stale')} className="btn-secondary text-sm">
                            Past review
                        </Link>
                        {can.manage && (
                            <button type="button" className="btn-primary text-sm" onClick={() => setCreating((v) => !v)}>
                                New plan
                            </button>
                        )}
                    </div>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    { label: 'Approved plans', value: currency.approved ?? 0 },
                    { label: 'Current', value: currency.current ?? 0 },
                    { label: 'Past review', value: currency.stale ?? 0, alarm: true },
                    {
                        label: 'Plans current',
                        value: currency.percentage == null ? '—' : `${currency.percentage}%`,
                        note: currency.percentage == null
                            ? 'No approved plans to measure'
                            : currency.undated > 0 ? `${currency.undated} have no review date` : null,
                    },
                ].map((tile) => (
                    <div
                        key={tile.label}
                        className={`rounded-lg border p-4 ${tile.alarm && tile.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}
                    >
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{tile.value}</p>
                        {tile.note && <p className="mt-1 text-[11px] text-amber-700">{tile.note}</p>}
                    </div>
                ))}
            </div>

            {creating && can.manage && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        create.post(tryRoute('bcms.plans.store'), { onSuccess: () => { create.reset(); setCreating(false); } });
                    }}
                    className="mb-6 space-y-4 rounded-lg border border-gray-200 bg-white p-6"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="text-sm">
                            <span className="text-gray-700">Plan type</span>
                            <select
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={create.data.plan_type}
                                onChange={(e) => { create.setData('plan_type', e.target.value); create.setData('template_key', ''); }}
                            >
                                {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                            </select>
                        </label>
                        <label className="text-sm">
                            <span className="text-gray-700">Title</span>
                            <input
                                type="text" required
                                className="mt-1 w-full rounded border-gray-300 text-sm"
                                value={create.data.title}
                                onChange={(e) => create.setData('title', e.target.value)}
                            />
                            {create.errors.title && <span className="text-xs text-red-600">{create.errors.title}</span>}
                        </label>
                    </div>

                    <label className="block text-sm">
                        <span className="text-gray-700">Start from a template</span>
                        <select
                            className="mt-1 w-full rounded border-gray-300 text-sm"
                            value={create.data.template_key}
                            onChange={(e) => create.setData('template_key', e.target.value)}
                        >
                            <option value="">Blank — add sections by hand</option>
                            {templatesForType.map((t) => (
                                <option key={t.key} value={t.key}>
                                    {t.label} — {t.section_count} sections, {t.bound_section_count} bound to live data
                                </option>
                            ))}
                        </select>
                        {create.data.template_key && (
                            <span className="mt-1 block text-xs text-gray-600">
                                {templates.find((t) => t.key === create.data.template_key)?.summary}
                            </span>
                        )}
                        {create.errors.template_key && (
                            <span className="text-xs text-red-600">{create.errors.template_key}</span>
                        )}
                    </label>

                    <label className="block text-sm sm:w-64">
                        <span className="text-gray-700">Review every (months)</span>
                        <input
                            type="number" min="1" max="120"
                            className="mt-1 w-full rounded border-gray-300 text-sm"
                            value={create.data.review_frequency_months}
                            onChange={(e) => create.setData('review_frequency_months', e.target.value)}
                        />
                        <span className="mt-1 block text-xs text-gray-500">
                            Leave blank for no review cycle. A plan with no cycle gets no review date and shows as undated
                            rather than current.
                        </span>
                    </label>

                    <div className="flex gap-2">
                        <button type="submit" className="btn-primary text-sm" disabled={create.processing}>Create</button>
                        <button type="button" className="btn-secondary text-sm" onClick={() => setCreating(false)}>Cancel</button>
                    </div>
                </form>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-3 text-xs">
                <select
                    className="rounded border-gray-300 text-xs"
                    value={filters.type ?? ''}
                    onChange={(e) => filter('type', e.target.value)}
                >
                    <option value="">All types</option>
                    {types.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
                <select
                    className="rounded border-gray-300 text-xs"
                    value={filters.status ?? ''}
                    onChange={(e) => filter('status', e.target.value)}
                >
                    <option value="">Current versions</option>
                    <option value="draft">Draft</option>
                    <option value="review">In review</option>
                    <option value="approved">Approved</option>
                    <option value="archived">Archived</option>
                </select>
            </div>

            {scope_note && (
                <p className="mb-4 rounded border border-gray-200 bg-gray-50 p-3 text-xs text-gray-700">{scope_note}</p>
            )}

            {can.manage && selected.length > 0 && (
                <form onSubmit={applyCycle} className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-300 bg-gray-50 p-4">
                    <p className="text-sm text-gray-800">
                        {selected.length} selected. Set their review cycle to
                    </p>
                    <label className="text-sm">
                        <input
                            type="number" min="1" max="120" placeholder="months"
                            className="w-28 rounded border-gray-300 text-sm"
                            value={cycle.data.review_frequency_months}
                            onChange={(e) => cycle.setData('review_frequency_months', e.target.value)}
                        />
                    </label>
                    <button type="submit" className="btn-primary text-sm" disabled={cycle.processing}>Apply</button>
                    <button type="button" className="btn-secondary text-sm" onClick={() => setSelected([])}>Clear</button>
                    <p className="w-full text-xs text-gray-600">
                        The new review date is counted from each plan&rsquo;s <strong>effective date</strong>, not from
                        today — so this cannot be used to clear the past-review list. Leave the box empty to remove the
                        cycle, which leaves those plans undated rather than current.
                    </p>
                </form>
            )}

            <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            {can.manage && (
                                <th className="px-4 py-3 text-left">
                                    <input
                                        type="checkbox"
                                        aria-label="Select every plan"
                                        checked={plans.length > 0 && selected.length === plans.length}
                                        onChange={(e) => setSelected(e.target.checked ? plans.map((p) => p.id) : [])}
                                    />
                                </th>
                            )}
                            <th className="px-4 py-3 text-left">Plan</th>
                            <th className="px-4 py-3 text-left">Type</th>
                            <th className="px-4 py-3 text-left">Version</th>
                            <th className="px-4 py-3 text-left">Owner</th>
                            <th className="px-4 py-3 text-left">Next review</th>
                            <th className="px-4 py-3 text-left">State</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {plans.length === 0 && (
                            <tr>
                                <td colSpan={can.manage ? 7 : 6} className="px-4 py-8 text-center text-gray-600">
                                    No plans yet. Create one from a template — the bound sections will fill themselves in
                                    from the BIA.
                                </td>
                            </tr>
                        )}

                        {plans.map((plan) => (
                            <tr key={plan.id} className={plan.is_stale ? 'bg-red-50' : undefined}>
                                {can.manage && (
                                    <td className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            aria-label={`Select ${plan.title}`}
                                            checked={selected.includes(plan.id)}
                                            onChange={() => toggle(plan.id)}
                                        />
                                    </td>
                                )}
                                <td className="px-4 py-3">
                                    <Link href={tryRoute('bcms.plans.show', plan.uuid)} className="font-medium text-gray-900 hover:underline">
                                        {plan.title}
                                    </Link>
                                    <span className="block text-xs text-gray-500">
                                        {plan.business_unit ?? plan.site ?? 'Organisation-wide'}
                                        {' · '}{plan.section_count} sections
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-xs text-gray-600">{plan.plan_type_label}</td>
                                <td className="px-4 py-3 font-mono text-xs">{plan.version}</td>
                                <td className="px-4 py-3 text-xs text-gray-600">{plan.owner ?? '—'}</td>
                                <td className="px-4 py-3 text-xs">
                                    {plan.next_review_date ?? <span className="text-amber-700">Not set</span>}
                                </td>
                                <td className="space-x-1 px-4 py-3 text-xs">
                                    <span className={`rounded px-2 py-1 ${plan.status === 'approved'
                                        ? 'bg-green-100 text-green-800'
                                        : plan.status === 'review'
                                            ? 'bg-blue-100 text-blue-800'
                                            : 'bg-gray-100 text-gray-700'}`}>
                                        {plan.status}
                                    </span>
                                    {plan.is_stale && <span className="rounded bg-red-100 px-2 py-1 text-red-800">Past review</span>}
                                    {plan.needs_review && (
                                        <span className="rounded bg-amber-100 px-2 py-1 text-amber-800">
                                            {plan.drifted_section_count} drifted
                                        </span>
                                    )}
                                    {plan.ai_generated && <span className="rounded bg-purple-100 px-2 py-1 text-purple-800">AI draft</span>}
                                    {plan.offline_bundle_generated_at && (
                                        <span className="rounded bg-gray-100 px-2 py-1 text-gray-700">Offline</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
