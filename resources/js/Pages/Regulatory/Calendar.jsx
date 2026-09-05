import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';

const MONTHS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

/** Regulatory filing calendar, one month at a time (migration Phase 5.3). */
export default function Calendar({ deadlines, month, year }) {
    const go = (targetMonth, targetYear) =>
        router.get(route('risk.regulatory.calendar'), { month: targetMonth, year: targetYear }, { preserveScroll: true });

    const previous = month === 1 ? { month: 12, year: year - 1 } : { month: month - 1, year };
    const next = month === 12 ? { month: 1, year: year + 1 } : { month: month + 1, year };

    // Grouped by day, in the order the server sent them (deadline_date ascending).
    const byDay = deadlines.reduce((groups, deadline) => {
        const key = deadline.deadline_date.slice(0, 10);
        (groups[key] ??= []).push(deadline);

        return groups;
    }, {});

    const days = Object.keys(byDay);

    return (
        <AuthenticatedLayout title="Regulatory Calendar">
            <Head title="Regulatory Calendar" />

            <PageHeader
                title={`Regulatory Calendar — ${MONTHS[month - 1]} ${year}`}
                subtitle="Filings due to the regulators this month"
                actions={
                    <>
                        <button type="button" onClick={() => go(previous.month, previous.year)} className="btn-secondary text-sm">
                            ← Prev
                        </button>
                        <button type="button" onClick={() => go(next.month, next.year)} className="btn-secondary text-sm">
                            Next →
                        </button>
                        <Link href={route('risk.regulatory.deadlines')} className="btn-secondary text-sm">
                            All deadlines
                        </Link>
                    </>
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 p-6">
                {days.length === 0 ? (
                    <p className="text-center py-12 text-gray-400">No deadlines this month</p>
                ) : (
                    days.map((day, index) => (
                        <div key={day} className={`py-3 ${index < days.length - 1 ? 'border-b border-gray-100' : ''}`}>
                            <p className="text-sm font-semibold text-gray-700 mb-2">
                                {new Date(day).toLocaleDateString('en-GB', {
                                    weekday: 'long',
                                    month: 'long',
                                    day: 'numeric',
                                })}
                            </p>
                            {byDay[day].map((deadline) => (
                                <div key={deadline.id} className="ml-4 flex items-center gap-3 py-1">
                                    <span
                                        className={`w-2 h-2 rounded-full ${deadline.is_overdue ? 'bg-red-500' : 'bg-green-500'}`}
                                        title={deadline.is_overdue ? 'Overdue' : 'On time'}
                                    />
                                    <span className="text-sm">{deadline.title}</span>
                                    <span className="badge bg-blue-50 text-blue-700 text-[10px]">{deadline.regulator}</span>
                                    <span className="text-xs text-gray-400">{deadline.responsible?.name ?? 'Unassigned'}</span>
                                </div>
                            ))}
                        </div>
                    ))
                )}
            </div>
        </AuthenticatedLayout>
    );
}
