import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * What I owe, across every exercise.
 *
 * THE EMPLOYEE'S VIEW OF THE COUNTDOWN. The daily digest tells somebody they
 * have three items outstanding; this is where they go to see which three. Sorted
 * by due date rather than by exercise, because the question is "what do I do
 * today", not "which exercise is this for".
 */
export default function MyReadiness({ tasks = [] }) {
    const overdue = tasks.filter((t) => t.is_overdue).length;

    return (
        <AppLayout title="My readiness tasks">
            <Head title="My readiness tasks" />

            <PageHeader
                title="My readiness tasks"
                subtitle={tasks.length === 0
                    ? 'Nothing is outstanding for you.'
                    : `${tasks.length} outstanding${overdue > 0 ? `, ${overdue} overdue` : ''}.`}
                actions={<Link href={tryRoute('bcms.calendar.index')} className="btn-secondary text-sm">Calendar</Link>}
            />

            {tasks.length === 0 ? (
                <div className="rounded-lg border border-green-200 bg-green-50 p-8 text-center text-sm text-green-800">
                    You have nothing outstanding. When an exercise is scheduled that needs something from you, it will
                    appear here and in your daily digest.
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th className="px-4 py-3 text-left">Due</th>
                                <th className="px-4 py-3 text-left">What</th>
                                <th className="px-4 py-3 text-left">For which exercise</th>
                                <th className="px-4 py-3 text-left" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {tasks.map((t) => (
                                <tr key={t.id} className={t.is_overdue ? 'bg-red-50' : undefined}>
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {t.due_date ?? '—'}
                                        {t.is_overdue && <span className="ml-1 text-red-700">overdue</span>}
                                    </td>
                                    <td className="px-4 py-3">
                                        {t.title}
                                        {t.is_blocking && (
                                            <span className="ml-2 rounded bg-gray-900 px-1.5 py-0.5 text-[10px] uppercase text-white">
                                                blocking
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-xs text-gray-600">
                                        {t.exercise}
                                        {t.exercise_date && <span className="block text-gray-500">{t.exercise_date}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right text-xs">
                                        {t.occurrence_uuid && (
                                            <Link
                                                href={tryRoute('bcms.occurrences.readiness', t.occurrence_uuid)}
                                                className="text-blue-700 hover:underline"
                                            >
                                                Open
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}
