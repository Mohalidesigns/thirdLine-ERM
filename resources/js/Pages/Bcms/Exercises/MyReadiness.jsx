import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import ReadinessTaskComplete from '@/Components/Bcms/ReadinessTaskComplete';

/**
 * What I owe, across every exercise.
 *
 * THE EMPLOYEE'S VIEW OF THE COUNTDOWN. The daily digest tells somebody they
 * have three items outstanding; this is where they go to see which three. Sorted
 * by due date rather than by exercise, because the question is "what do I do
 * today", not "which exercise is this for".
 *
 * GAP 5: EVERY ROW CARRIES ITS OWN `complete_url` NOW (`ReadinessService::
 * forUser()`) — the owner completes their own task from here, without
 * needing the broader `bcms.exercise.view`/`.facilitate` grant the full
 * occurrence readiness screen sits behind. `ReadinessTaskComplete` is the
 * exact control `Exercises/Readiness.jsx` uses for the same action, so a
 * task that `requires_evidence` behaves identically in both places.
 *
 * D3: the "Open" link to the full occurrence readiness screen needs
 * `bcms.exercise.view`, which a `my.view`-only owner does not hold — so
 * `readiness_url` (the same key `Calendar/Index.jsx` already uses for this
 * exact route, `CalendarService`'s `readiness_url`) is only present on a row
 * when the current user is allowed to open it. No key, no link — not a
 * disabled button, no row at all for that action.
 *
 * The "Calendar" header button has the same shape of problem: a `my.view`-only
 * owner (e.g. seeded user Oluwaseun) does not hold the grant the calendar screen
 * sits behind and gets a 403 if the button is always shown. `calendar_url` is
 * only present on the page props when the current user may open it — no key,
 * no button, in both the populated and empty states, never a disabled one.
 */
export default function MyReadiness({ tasks = [], calendar_url = null }) {
    const { flash } = usePage().props;
    const overdue = tasks.filter((t) => t.is_overdue).length;

    return (
        <AppLayout title="My readiness tasks">
            <Head title="My readiness tasks" />

            <PageHeader
                title="My readiness tasks"
                subtitle={tasks.length === 0
                    ? 'Nothing is outstanding for you.'
                    : `${tasks.length} outstanding${overdue > 0 ? `, ${overdue} overdue` : ''}.`}
                actions={calendar_url && (
                    <Link href={calendar_url} className="btn-secondary text-sm">Calendar</Link>
                )}
            />

            {flash?.success && (
                <div role="status" className="mb-4 rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-900">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div role="alert" className="mb-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                    {flash.error}
                </div>
            )}

            {tasks.length === 0 ? (
                <div className="rounded-lg border border-green-200 bg-green-50 p-8 text-center text-sm text-green-800">
                    You have nothing outstanding. When an exercise is scheduled that needs something from you, it will
                    appear here and in your daily digest.
                </div>
            ) : (
                <div className="card">
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Due</th>
                                    <th>What</th>
                                    <th>For which exercise</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {tasks.map((t) => (
                                    <tr key={t.id} className={t.is_overdue ? 'bg-red-50' : undefined}>
                                        <td className="font-mono text-xs">
                                            {t.due_date ?? '—'}
                                            {t.is_overdue && <span className="ml-1 text-red-700">overdue</span>}
                                        </td>
                                        <td>
                                            {t.title}
                                            {t.is_blocking && (
                                                <span className="ml-2 rounded bg-gray-900 px-1.5 py-0.5 text-[10px] uppercase text-white">
                                                    blocking
                                                </span>
                                            )}
                                            {t.requires_evidence && (
                                                <span className="ml-1 rounded bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-800">
                                                    evidence
                                                </span>
                                            )}
                                        </td>
                                        <td className="cell-muted">
                                            {t.exercise}
                                            {t.exercise_date && <span className="block text-gray-500">{t.exercise_date}</span>}
                                        </td>
                                        <td className="text-right text-xs">
                                            <span className="inline-flex items-center gap-3">
                                                {t.complete_url && (
                                                    <ReadinessTaskComplete
                                                        completeUrl={t.complete_url}
                                                        requiresEvidence={t.requires_evidence}
                                                        taskTitle={t.title}
                                                    />
                                                )}
                                                {t.readiness_url && (
                                                    <Link
                                                        href={t.readiness_url}
                                                        className="text-blue-700 hover:underline"
                                                    >
                                                        Open
                                                    </Link>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
