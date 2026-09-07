import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The access reconciliation report — FR-ACC-03.
 *
 * "Revoke the credentials of discontinued providers" is a control every
 * framework asks for and almost nobody can evidence, because the evidence
 * requires joining two registers most banks keep in different systems. This
 * screen IS that join, and it is the landing page rather than a tab behind the
 * connection list for the reason the list itself is not interesting: a filing
 * cabinet of every connection ever opened answers no question anybody asked.
 *
 * FOUR POPULATIONS, NOT ONE LIST. They are four different failures with four
 * different owners, and rolling them together buries the genuinely alarming
 * rows under the merely untidy ones.
 */
export default function Index({ reconciliation = {}, can = {} }) {
    const sections = [
        {
            key: 'discontinued',
            title: 'Access against ended relationships',
            blurb: 'The engagement is terminated or archived and the credential is still live. This is the control having failed.',
            tone: 'critical',
            rows: reconciliation.discontinued ?? [],
        },
        {
            key: 'expired_contract',
            title: 'Access with no contract behind it',
            blurb: 'The engagement still looks live, but every contract under it has run out — the same exposure wearing a healthier status.',
            tone: 'critical',
            rows: reconciliation.expired_contract ?? [],
        },
        {
            key: 'overdue',
            title: 'Grants past their end date',
            blurb: 'The clock ran out and nobody revoked. Each of these should already have raised a Critical finding.',
            tone: 'warn',
            rows: reconciliation.overdue ?? [],
        },
        {
            key: 'open_ended',
            title: 'Grants with no end date',
            blurb: 'Worse than overdue: no clock was ever set. Third-party access is meant to be time-bounded.',
            tone: 'warn',
            rows: reconciliation.open_ended ?? [],
        },
    ];

    const total = sections.reduce((sum, section) => sum + section.rows.length, 0);
    const openConnections = reconciliation.open_connections ?? [];

    return (
        <AppLayout title="Access reconciliation">
            <Head title="Access reconciliation" />

            <PageHeader
                title="Access reconciliation"
                subtitle="Third-party credentials and connections that have outlived the relationship they were opened for."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {sections.map((section) => (
                    <div key={section.key} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{section.title}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            section.rows.length === 0
                                ? 'text-gray-900'
                                : section.tone === 'critical' ? 'text-red-700' : 'text-amber-700'
                        }`}>
                            {section.rows.length}
                        </p>
                    </div>
                ))}
            </div>

            {total === 0 && openConnections.length === 0 ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    Nothing outstanding. Every live grant sits under a live engagement with a contract and an end
                    date, and no connection survives an ended relationship.
                </div>
            ) : (
                <div className="space-y-6">
                    {sections.filter((section) => section.rows.length > 0).map((section) => (
                        <GrantSection key={section.key} section={section} can={can} />
                    ))}

                    {openConnections.length > 0 && (
                        <section>
                            <h2 className="text-sm font-semibold text-gray-900">Connections still open</h2>
                            <p className="mb-2 text-xs text-gray-600">
                                Technical paths against engagements that have ended. A suspended tunnel is still a
                                tunnel whose configuration exists.
                            </p>
                            <div className="card overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Connection</th>
                                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Type</th>
                                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Endpoint</th>
                                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Third party</th>
                                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Engagement</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {openConnections.map((row) => (
                                            <tr key={row.id}>
                                                <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">{row.name}</th>
                                                <td className="px-4 py-2">{row.type}</td>
                                                <td className="px-4 py-2 font-mono text-xs text-gray-600">{row.endpoint ?? '—'}</td>
                                                <td className="px-4 py-2">{row.third_party}</td>
                                                <td className="px-4 py-2">
                                                    {row.engagement_uuid ? (
                                                        <Link
                                                            href={route('tprm.access.show', row.engagement_uuid)}
                                                            className="text-blue-700 hover:underline"
                                                        >
                                                            {row.engagement_name}
                                                        </Link>
                                                    ) : row.engagement_name}
                                                    <span className="ml-1 text-xs text-gray-500">({row.engagement_status})</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    )}
                </div>
            )}

            <p className="mt-6 text-xs text-gray-500">
                Generated {reconciliation.generated_at}. Privileged and administrative access is listed first
                within each section.
            </p>
        </AppLayout>
    );
}

function GrantSection({ section }) {
    return (
        <section>
            <h2 className="text-sm font-semibold text-gray-900">{section.title}</h2>
            <p className="mb-2 text-xs text-gray-600">{section.blurb}</p>
            <div className="card overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Grantee</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">System</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Access</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Ends</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Third party</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Engagement</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {section.rows.map((row) => (
                            <tr key={row.id}>
                                <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">
                                    {row.grantee_name}
                                    {row.grantee_email && (
                                        <span className="block text-xs font-normal text-gray-500">{row.grantee_email}</span>
                                    )}
                                </th>
                                <td className="px-4 py-2">{row.system_name}</td>
                                <td className="px-4 py-2">
                                    <span className={row.is_privileged ? 'font-semibold text-red-700' : ''}>
                                        {row.access_level_label}
                                    </span>
                                </td>
                                <td className="px-4 py-2">
                                    {row.valid_to ?? <span className="text-amber-700">no end date</span>}
                                </td>
                                <td className="px-4 py-2">{row.third_party}</td>
                                <td className="px-4 py-2">
                                    {row.engagement_uuid ? (
                                        <Link
                                            href={route('tprm.access.show', row.engagement_uuid)}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {row.engagement_name}
                                        </Link>
                                    ) : row.engagement_name}
                                    {row.engagement_status && (
                                        <span className="ml-1 text-xs text-gray-500">({row.engagement_status})</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}
