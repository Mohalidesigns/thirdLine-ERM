import { Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';

export default function Index({ assessments = [] }) {
    return (
        <PortalLayout title="Assessments">
            <h1 className="mb-4 text-lg font-semibold text-gray-900">Assessments</h1>

            {assessments.length === 0 ? (
                <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                    Nothing has been sent to you yet.
                </div>
            ) : (
                <div className="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white">
                    {assessments.map((assessment) => (
                        <Link
                            key={assessment.uuid}
                            href={route('tprm-portal.assessments.show', assessment.uuid)}
                            className="block px-4 py-3 hover:bg-gray-50"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-sm font-medium text-gray-900">{assessment.name}</span>
                                <span className="text-xs text-gray-500">{assessment.status_label}</span>
                            </div>
                            <p className="mt-0.5 text-xs text-gray-500">
                                {assessment.engagement}
                                {assessment.due_at && (
                                    <span className={assessment.overdue ? ' font-semibold text-red-700' : ''}>
                                        {' · '}due {assessment.due_at}
                                    </span>
                                )}
                            </p>
                            <div className="mt-2 h-1.5 w-full overflow-hidden rounded bg-gray-100">
                                <div className="h-full bg-blue-600" style={{ width: `${assessment.progress?.pct ?? 0}%` }} />
                            </div>
                        </Link>
                    ))}
                </div>
            )}
        </PortalLayout>
    );
}
