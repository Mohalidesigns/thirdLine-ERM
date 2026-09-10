import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * BCMS home — the resilience posture screen (Blueprint §15, screen 1).
 *
 * WHAT IS NOT HERE IN PHASE 0, AND WHY THAT IS THE POINT. The blueprint's home
 * screen carries a maturity score, a plans-current donut and an
 * exercises-completed-versus-planned ring. Three of those have no data until
 * their phase lands, and the honest rendering of an undefined rate is a
 * sentence, not a zero: an empty plan register has no currency rate. A green
 * "100% current" over nothing is the exact defect NoFabricatedNumbersTest and
 * development standard §5 exist to prevent, and it is the one that got a
 * capital adequacy ratio of 15.2% onto a board pack.
 */
export default function Home({ programme, counts, plans_current_rate: plansCurrentRate, upcoming = [], sections = [], mock_channels: mockChannels = [] }) {
    const tiles = [
        { label: 'Processes in scope', value: counts?.processes, hint: `${counts?.critical_processes ?? 0} flagged as a critical service` },
        { label: 'Approved plans', value: counts?.plans_approved, hint: plansCurrentRate === null ? 'No approved plans yet, so currency is undefined' : `${plansCurrentRate}% within their review date` },
        { label: 'Exercises planned this year', value: counts?.occurrences_planned, hint: `${counts?.occurrences_completed ?? 0} completed` },
        { label: 'Open corrective actions', value: counts?.actions_open, hint: `${counts?.actions_overdue ?? 0} past their due date` },
    ];

    return (
        <AppLayout title="Business continuity">
            <Head title="Business continuity" />

            <PageHeader
                title="Business continuity"
                subtitle={programme ? `${programme.name} · ${programme.status}` : 'No programme has been created for this year yet.'}
            />

            {mockChannels.length > 0 && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">Notification channels are in test mode.</p>
                    <p className="mt-1">
                        {mockChannels.join(', ')} record what would be sent and dispatch nothing. Real gateways are
                        connected in Phase 7. Nothing sent from this module reaches a handset until then.
                    </p>
                </div>
            )}

            <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="rounded-lg border border-gray-200 bg-white p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className="mt-2 font-mono text-2xl text-gray-900">{tile.value ?? '—'}</p>
                        <p className="mt-1 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section className="lg:col-span-1">
                    <h2 className="mb-3 text-sm font-semibold text-gray-900">Next 30 days</h2>
                    {upcoming.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                            Nothing is scheduled in the next thirty days. The resilience calendar generates occurrences
                            from the annual programme in Phase 4.
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                            {upcoming.map((occurrence) => (
                                <li key={occurrence.uuid} className="p-3 text-sm">
                                    <p className="font-medium text-gray-900">{occurrence.name}</p>
                                    <p className="text-xs text-gray-500">
                                        {occurrence.scheduled_date} · {occurrence.status}
                                        {occurrence.blocking_tasks_open > 0
                                            ? ` · ${occurrence.blocking_tasks_open} blocking task(s) open`
                                            : ''}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="lg:col-span-2">
                    <h2 className="mb-3 text-sm font-semibold text-gray-900">The module</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        {sections.map((section) => (
                            <Link
                                key={section.key}
                                href={tryRoute(`bcms.${section.key}.index`)}
                                className="rounded-lg border border-gray-200 bg-white p-4 transition hover:border-gray-400"
                            >
                                <p className="text-sm font-semibold text-gray-900">{section.label}</p>
                                <p className="mt-1 text-xs text-gray-600">{section.summary}</p>
                                <p className="mt-2 text-[11px] uppercase tracking-wide text-gray-400">{section.clause}</p>
                            </Link>
                        ))}
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
