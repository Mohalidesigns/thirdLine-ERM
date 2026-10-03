import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import { formatIncidentDateTime } from '@/Components/Bcms/dateDisplay';

/**
 * A minimal, real incidents register — out of full spec per
 * `incident-declaration.md` §8 ("not specified here"), built only so the
 * nav's now-`live` `incidents` section has somewhere real to land.
 */
export default function Index({ incidents, declare_url: declareUrl }) {
    const rows = incidents?.data ?? [];

    return (
        <AppLayout title="Incidents">
            <Head title="Incidents" />

            <PageHeader
                title="Incidents"
                subtitle="Every declared incident, real or exercise."
                actions={<Link href={declareUrl} className="btn-primary text-sm">Declare an incident</Link>}
            />

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Title</th>
                                <th>Severity</th>
                                <th>Status</th>
                                <th>Declared</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr><td colSpan={5} className="py-12 text-center text-sm text-gray-500">
                                    No incidents recorded yet.
                                </td></tr>
                            )}
                            {rows.map((i) => (
                                <tr key={i.uuid}>
                                    <td>
                                        <Link href={i.show_url} className="cell-title">{i.reference}</Link>
                                        {i.is_exercise && <span className="ml-1 rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-800">exercise</span>}
                                    </td>
                                    <td>{i.title}</td>
                                    <td className="uppercase text-xs">{i.severity ?? '—'}</td>
                                    <td className="capitalize">{i.status ?? '—'}</td>
                                    <td className="cell-muted">{formatIncidentDateTime(i.declared_at) ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {incidents?.links && incidents.links.length > 3 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={incidents.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
