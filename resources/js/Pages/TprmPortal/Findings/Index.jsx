import { Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';

export default function Index({ findings = [] }) {
    return (
        <PortalLayout title="Findings">
            <h1 className="mb-1 text-lg font-semibold text-gray-900">Findings</h1>
            <p className="mb-4 text-sm text-gray-600">
                Gaps your client has recorded. You respond and attach evidence here; they verify and close.
            </p>

            {findings.length === 0 ? (
                <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                    No findings have been raised against you.
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Reference</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Finding</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Severity</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Due</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {findings.map((finding) => (
                                <tr key={finding.uuid}>
                                    <th scope="row" className="px-4 py-2 text-left font-mono text-xs text-gray-600">
                                        <Link
                                            href={route('tprm-portal.findings.show', finding.uuid)}
                                            className="text-blue-700 hover:underline"
                                        >
                                            {finding.reference}
                                        </Link>
                                    </th>
                                    <td className="px-4 py-2">{finding.title}</td>
                                    <td className="px-4 py-2">{finding.severity_label}</td>
                                    <td className="px-4 py-2">{finding.status_label}</td>
                                    <td className={`px-4 py-2 ${finding.overdue ? 'font-semibold text-red-700' : ''}`}>
                                        {finding.target_date ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </PortalLayout>
    );
}
