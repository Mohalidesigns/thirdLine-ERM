import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import RatingBadge from '@thirdline/ui/Components/RatingBadge';

/**
 * Step 1 of the chain (migration Phase 3.3, from
 * risk/assessments/select-risk.blade.php).
 *
 * Which controls to rate and which causes to review are properties of the
 * risk, so a request with no `risk_id` gets this rather than a form full of
 * empty selects.
 */
export default function SelectRisk({ risks = [] }) {
    const [search, setSearch] = useState('');

    const matches = useMemo(() => {
        const needle = search.trim().toLowerCase();

        if (needle === '') return risks;

        return risks.filter(
            (risk) =>
                (risk.risk_code ?? '').toLowerCase().includes(needle) ||
                (risk.title ?? '').toLowerCase().includes(needle) ||
                (risk.category ?? '').toLowerCase().includes(needle),
        );
    }, [risks, search]);

    return (
        <AuthenticatedLayout title="New Assessment">
            <Head title="New Assessment" />

            <PageHeader
                title="Which risk are you assessing?"
                subtitle="The rest of the chain — causes, controls, residual risk — belongs to the risk you pick."
                breadcrumbs={[
                    { label: 'Assessments', href: route('risk.assessments.index') },
                    { label: 'New Assessment' },
                ]}
            />

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search by risk ID, title or category…"
                    className="w-full px-4 py-2 border border-gray-300 rounded-lg text-sm"
                />
            </div>

            {matches.length === 0 ? (
                <EmptyState
                    icon="search_off"
                    title={risks.length === 0 ? 'No active risks to assess.' : 'No risks match that search.'}
                    description={
                        risks.length === 0
                            ? 'An assessment is an assessment of something — register a risk first.'
                            : undefined
                    }
                />
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {matches.map((risk) => (
                        <Link
                            key={risk.id}
                            href={route('risk.assessments.create', { risk_id: risk.id })}
                            className="bg-white rounded-xl border border-gray-200 p-4 hover:border-[#1A365D] hover:shadow-sm transition-all"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-[#1A365D]">{risk.risk_code}</p>
                                    <p className="text-sm text-gray-700 truncate">{risk.title}</p>
                                    {risk.category && <p className="text-xs text-gray-500 mt-1">{risk.category}</p>}
                                </div>
                                <div className="flex-shrink-0 text-right">
                                    {risk.inherent_rating && <RatingBadge rating={risk.inherent_rating} />}
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </AuthenticatedLayout>
    );
}
