import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The register as at the close of a period that has ended (migration Phase
 * 3.2, from risk/register/historic.blade.php).
 *
 * Untouched by the WP-09 grid migration and still not on the DataGrid: the
 * scores are measure-engine overlay values that exist only in memory, so
 * there is no SQL column for the grid to sort or filter on. Filtering, sorting
 * and paging stay server-side over the overlaid collection.
 */
export default function Historic({ risks, categories = [], businessUnits = [], asOfPeriod, filters = {} }) {
    const [form, setForm] = useState({
        search: filters.search ?? '',
        category: filters.category ?? '',
        status: filters.status ?? '',
        business_unit: filters.business_unit ?? '',
    });

    const apply = (next) => {
        setForm(next);
        router.get(route('risk.register.index'), next, { preserveState: true, replace: true });
    };

    const set = (field) => (e) => apply({ ...form, [field]: e.target.value });

    const currentPeriod = tryRoute('risk.periods.select', { direction: 'current', redirect: '/risk/register' });
    const rows = risks.data ?? [];

    return (
        <AuthenticatedLayout title="Risk Register">
            <Head title="Risk Register" />

            <div className="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-xl flex items-start gap-3">
                <span className="material-symbols-outlined text-blue-600">history</span>
                <div className="text-sm text-blue-800">
                    <p className="font-semibold">
                        Showing the register as at {asOfPeriod.name}
                        {asOfPeriod.end_date && ` (${new Date(asOfPeriod.end_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })})`}.
                    </p>
                    <p className="text-xs text-blue-700 mt-0.5">
                        Scores are the last approved on or before that date. Risks identified afterwards are excluded.
                        {currentPeriod && <> <a href={currentPeriod} className="underline font-medium">Return to the current period</a>.</>}
                    </p>
                </div>
            </div>

            <PageHeader title="Risk Register" subtitle={`${risks.total ?? 0} risks registered`} />

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex flex-wrap gap-3">
                <input
                    type="search"
                    value={form.search}
                    onChange={(e) => setForm({ ...form, search: e.target.value })}
                    onKeyDown={(e) => e.key === 'Enter' && apply(form)}
                    placeholder="Search by Risk ID, name, description..."
                    className="flex-1 min-w-[240px] border border-gray-300 rounded-lg px-3 py-2 text-sm"
                />
                <select value={form.category} onChange={set('category')} className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Categories</option>
                    {categories.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                </select>
                <select value={form.business_unit} onChange={set('business_unit')} className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Business Units</option>
                    {businessUnits.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                </select>
                <select value={form.status} onChange={set('status')} className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700">
                    <option value="">All Statuses</option>
                    {['active', 'dormant', 'closed', 'retired'].map((value) => (
                        <option key={value} value={value}>{value.charAt(0).toUpperCase() + value.slice(1)}</option>
                    ))}
                </select>
            </div>

            {rows.length > 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>Risk ID</th>
                                    <th>Risk Name</th>
                                    <th>Category</th>
                                    <th>Inherent Rating</th>
                                    <th>Residual Rating</th>
                                    <th>Risk Owner</th>
                                    <th>Business Unit</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((risk) => (
                                    <tr key={risk.id} className="hover:bg-blue-50/50">
                                        <td className="font-medium text-[#1A365D]">{risk.risk_code}</td>
                                        <td className="text-sm">{risk.title}</td>
                                        <td className="text-sm">{risk.category ?? '-'}</td>
                                        <td>
                                            <span className="text-sm font-semibold">{risk.inherent_score ?? '-'}</span>
                                            {risk.inherent_rating && <RatingBadge rating={risk.inherent_rating} />}
                                        </td>
                                        <td>
                                            <span className="text-sm font-semibold">{risk.residual_score ?? '-'}</span>
                                            {risk.residual_rating && <RatingBadge rating={risk.residual_rating} />}
                                        </td>
                                        <td className="text-sm">{risk.risk_owner ?? '-'}</td>
                                        <td className="text-sm">{risk.business_unit ?? '-'}</td>
                                        <td><StatusBadge status={risk.status} /></td>
                                        <td>
                                            <Link href={route('risk.register.show', risk.id)} className="text-[#1A365D] text-sm underline">
                                                View
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination links={risks.links} meta={risks} />
                </div>
            ) : (
                <EmptyState icon="search_off" title="No risks found matching your criteria." />
            )}
        </AuthenticatedLayout>
    );
}
