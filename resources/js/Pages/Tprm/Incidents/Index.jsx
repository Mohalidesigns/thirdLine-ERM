import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The incident register, ordered by what is most urgent — AC-07.
 *
 * SORTED BY THE TIGHTEST RUNNING CLOCK, not by date. An incident reported this
 * morning with four hours left on a CBN window belongs above one reported last
 * week with nothing outstanding, and a register ordered by recency puts them
 * the other way round.
 */
export default function Index({ incidents = [], settings = {}, can = {} }) {
    const urgency = (incident) => {
        const running = (incident.clocks ?? []).filter((clock) => clock.state !== 'reported');

        if (running.length === 0) return Number.POSITIVE_INFINITY;

        return Math.min(...running.map((clock) => clock.hours_remaining));
    };

    const sorted = [...incidents].sort((a, b) => urgency(a) - urgency(b));

    return (
        <AppLayout title="Incidents">
            <Head title="Incidents" />

            <PageHeader
                title="Third-party incidents"
                subtitle="Ordered by the tightest clock still running, not by when they arrived."
            />

            {!settings.has_materiality_basis && (
                <div className="card mb-6 border-l-4 border-amber-400 p-4 text-sm text-gray-700">
                    {settings.note}
                </div>
            )}

            {sorted.length === 0 ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    No incidents recorded. Vendors report them through the portal, and your own staff can record
                    one from an engagement.
                </div>
            ) : (
                <div className="card overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Reference</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Incident</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Provider</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Clocks</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Told to us</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {sorted.map((incident) => (
                                <tr key={incident.uuid}>
                                    <th scope="row" className="px-4 py-2 text-left">
                                        <Link
                                            href={route('tprm.incidents.show', incident.uuid)}
                                            className="font-mono text-xs text-blue-700 hover:underline"
                                        >
                                            {incident.reference}
                                        </Link>
                                    </th>
                                    <td className="px-4 py-2">{incident.title}</td>
                                    <td className="px-4 py-2">{incident.third_party}</td>
                                    <td className="px-4 py-2">
                                        <div className="flex flex-wrap gap-1">
                                            {(incident.clocks ?? []).length === 0 && (
                                                <span className="text-xs text-gray-400">none</span>
                                            )}
                                            {(incident.clocks ?? []).map((clock) => (
                                                <ClockChip key={clock.regulator} clock={clock} />
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2 text-xs text-gray-500">{incident.reported_to_us_at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}

function ClockChip({ clock }) {
    const tone = {
        reported: 'bg-green-50 text-green-800',
        breached: 'bg-red-100 text-red-900',
        critical: 'bg-red-50 text-red-800',
        warning: 'bg-amber-50 text-amber-800',
        running: 'bg-blue-50 text-blue-800',
    }[clock.state] ?? 'bg-gray-100 text-gray-700';

    return (
        <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${tone}`}>
            {clock.label}{' '}
            {clock.state === 'reported'
                ? 'done'
                : clock.hours_remaining >= 0
                    ? `${Math.floor(clock.hours_remaining)}h`
                    : `${Math.abs(Math.floor(clock.hours_remaining))}h over`}
        </span>
    );
}
