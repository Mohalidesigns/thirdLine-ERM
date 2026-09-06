import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';

/**
 * The configuration builder landing page (migration Phase 6.3).
 *
 * The cards arrive already filtered by permission: admin.metadata,
 * admin.scoring and admin.configuration are separately grantable, which is why
 * the routes sit in three middleware groups and why the filtering is the
 * server's rather than this page's.
 */
export default function Index({ cards }) {
    return (
        <AuthenticatedLayout title="Configuration Builder">
            <Head title="Configuration Builder" />

            <PageHeader
                title="Configuration Builder"
                subtitle="Define what this organisation governs, what it records, how it connects and how it is scored — without a code change or a release"
                breadcrumbs={[{ label: 'Administration' }, { label: 'Configuration Builder' }]}
            />

            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                {cards.map((card) => (
                    <Link
                        key={card.title}
                        href={card.href}
                        className="block bg-white rounded-xl border border-gray-200 p-5 hover:border-[#1A365D] hover:shadow-sm transition"
                    >
                        <div className="flex items-start justify-between">
                            <span className="material-symbols-outlined text-[#1A365D] text-2xl">{card.icon}</span>
                            {card.count !== null && (
                                <span className="text-2xl font-bold text-gray-900">{card.count}</span>
                            )}
                        </div>
                        <h3 className="mt-3 text-sm font-semibold text-gray-900">{card.title}</h3>
                        {card.noun && <p className="text-[11px] uppercase tracking-wide text-gray-400">{card.noun}</p>}
                        <p className="mt-2 text-xs text-gray-500 leading-relaxed">{card.blurb}</p>
                    </Link>
                ))}
            </div>

            <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
                <div className="flex gap-3">
                    <span className="material-symbols-outlined text-amber-600">warning</span>
                    <div className="text-xs text-amber-900 leading-relaxed">
                        <p className="font-semibold">Changes here affect every record in this organisation.</p>
                        <p className="mt-1">
                            Resizing a scoring matrix re-rates the whole register. Changing a field's data type can lose
                            what is already stored in it. Deleting a relationship type archives the relationships that
                            use it. Each screen tells you what it is about to affect before it does it — read that, and
                            export a configuration bundle first if the change is large.
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
