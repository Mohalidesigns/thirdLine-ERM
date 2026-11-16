import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Modal, PageHeader, StatCard } from '@thirdline/ui';
import { formatDateTime } from '@thirdline/ui/utils';
import { useMemo, useState } from 'react';

export default function Returns({
    returns = [],
    myReturns = [],
    periods = [],
    currentPeriod,
    currentPeriodLabel,
    stats = {},
    isChampionOnly = false,
    can = {},
}) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [confirmingReminder, setConfirmingReminder] = useState(false);
    const [submittingReturn, setSubmittingReturn] = useState(null);

    const nilForm = useForm({
        has_loss: false,
        comment: 'no loss was recorded',
    });

    const reminderForm = useForm({
        period_code: currentPeriod,
        business_unit_id: null,
    });

    const handlePeriodChange = (e) => {
        router.get(route('risk.loss-events.returns.index'), { period: e.target.value }, { preserveState: true });
    };

    const handleOpenNilModal = (ret) => {
        nilForm.setData({
            has_loss: false,
            comment: 'no loss was recorded',
        });
        setSubmittingReturn(ret);
    };

    const submitNilReturn = (e) => {
        e.preventDefault();
        if (!submittingReturn) return;

        nilForm.post(route('risk.loss-events.returns.submit', submittingReturn.id), {
            preserveScroll: true,
            onSuccess: () => setSubmittingReturn(null),
        });
    };

    const sendReminders = (e) => {
        e.preventDefault();
        reminderForm.post(route('risk.loss-events.returns.remind'), {
            preserveScroll: true,
            onSuccess: () => setConfirmingReminder(false),
        });
    };

    const filteredReturns = useMemo(() => {
        return returns.filter((row) => {
            const matchesSearch =
                !search ||
                row.business_unit?.name?.toLowerCase().includes(search.toLowerCase()) ||
                row.business_unit?.code?.toLowerCase().includes(search.toLowerCase());

            const matchesStatus =
                statusFilter === 'all' ||
                (statusFilter === 'pending' && row.status === 'pending') ||
                (statusFilter === 'loss' && row.status === 'submitted' && row.has_loss) ||
                (statusFilter === 'nil' && row.status === 'submitted' && !row.has_loss);

            return matchesSearch && matchesStatus;
        });
    }, [returns, search, statusFilter]);

    return (
        <AppLayout title={`Monthly Loss Returns - ${currentPeriodLabel}`}>
            <Head title={`Monthly Loss Returns - ${currentPeriodLabel}`} />

            <PageHeader
                title="Monthly Loss Event Returns"
                subtitle={`Departmental monthly returns, loss tracking, and nil declarations for ${currentPeriodLabel}`}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Period Selector */}
                        <div className="flex items-center gap-1.5 bg-white border border-gray-300 rounded-md px-2.5 py-1 text-sm shadow-sm">
                            <span className="material-symbols-outlined text-gray-500 text-base">calendar_month</span>
                            <select
                                value={currentPeriod}
                                onChange={handlePeriodChange}
                                className="border-0 bg-transparent py-0 pl-1 pr-6 text-sm font-medium text-gray-800 focus:ring-0 cursor-pointer"
                            >
                                {periods.map((p) => (
                                    <option key={p.code} value={p.code}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {can.send_reminder && stats.pending > 0 && (
                            <button
                                type="button"
                                onClick={() => {
                                    reminderForm.setData({ period_code: currentPeriod, business_unit_id: null });
                                    setConfirmingReminder(true);
                                }}
                                className="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium inline-flex items-center gap-1.5 shadow-sm transition"
                            >
                                <span className="material-symbols-outlined text-base">notifications_active</span>
                                Send Reminders ({stats.pending})
                            </button>
                        )}

                        {can.create_event && (
                            <Link
                                href={route('risk.loss-events.create')}
                                className="btn-primary text-sm inline-flex items-center gap-1.5 px-3 py-1.5"
                            >
                                <span className="material-symbols-outlined text-base">add</span>
                                Log Loss Event
                            </Link>
                        )}
                    </div>
                }
            />

            {/* Stat Cards */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-5">
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">corporate_fare</span>}
                    title="Total Departments"
                    value={stats.total_departments ?? 0}
                    color="blue"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">task_alt</span>}
                    title="Submitted Returns"
                    value={stats.submitted ?? 0}
                    color="green"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">report_problem</span>}
                    title="Losses Reported"
                    value={stats.loss_reported ?? 0}
                    color="red"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">check_circle</span>}
                    title="Nil Returns (No Loss)"
                    value={stats.nil_reported ?? 0}
                    color="teal"
                />
                <StatCard
                    icon={<span className="material-symbols-outlined text-xl">pending_actions</span>}
                    title="Pending Submissions"
                    value={stats.pending ?? 0}
                    color="amber"
                />
            </div>

            {/* Risk Champion Department Action Card */}
            {myReturns.length > 0 && (
                <div className="mb-8 space-y-4">
                    <h2 className="text-base font-semibold text-gray-900 flex items-center gap-2">
                        <span className="material-symbols-outlined text-blue-600">star</span>
                        Your Department's Monthly Return
                    </h2>

                    {myReturns.map((myRet) => (
                        <div
                            key={myRet.id}
                            className={`rounded-lg border p-5 shadow-sm bg-white ${
                                myRet.status === 'submitted'
                                    ? 'border-emerald-200 bg-emerald-50/20'
                                    : 'border-amber-200 bg-amber-50/20'
                            }`}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="text-lg font-bold text-gray-900">
                                            {myRet.business_unit?.name}
                                        </span>
                                        <span className="font-mono text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600 font-semibold">
                                            {myRet.business_unit?.code}
                                        </span>
                                    </div>
                                    <p className="text-sm text-gray-600 mt-1">
                                        Reporting Cycle: <strong>{currentPeriodLabel}</strong>
                                    </p>
                                </div>

                                <div>
                                    {myRet.status === 'submitted' ? (
                                        myRet.has_loss ? (
                                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 border border-red-200">
                                                <span className="h-1.5 w-1.5 rounded-full bg-red-600" />
                                                Loss Event(s) Reported ({myRet.loss_events_count})
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-600" />
                                                Nil Return: No Loss Recorded
                                            </span>
                                        )
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">
                                            <span className="h-1.5 w-1.5 rounded-full bg-amber-600 animate-pulse" />
                                            Return Pending for {currentPeriodLabel}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {myRet.status === 'pending' ? (
                                <div className="mt-4 pt-4 border-t border-amber-200/60">
                                    <p className="text-sm text-gray-700">
                                        Please submit your department's monthly loss return. If any operational loss
                                        occurred, log the event; if no loss occurred, submit a nil return.
                                    </p>
                                    <div className="mt-3 flex flex-wrap items-center gap-3">
                                        <Link
                                            href={route('risk.loss-events.create', {
                                                business_unit_id: myRet.business_unit_id,
                                            })}
                                            className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md shadow-sm inline-flex items-center gap-1.5 transition"
                                        >
                                            <span className="material-symbols-outlined text-sm">add_alert</span>
                                            Record a Loss Event
                                        </Link>

                                        <button
                                            type="button"
                                            onClick={() => handleOpenNilModal(myRet)}
                                            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-md shadow-sm inline-flex items-center gap-1.5 transition"
                                        >
                                            <span className="material-symbols-outlined text-sm">check_circle</span>
                                            No Loss Was Recorded (Nil Return)
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="mt-4 pt-4 border-t border-emerald-200/60 text-xs text-gray-600 flex flex-wrap items-center gap-x-6 gap-y-1">
                                    <div>
                                        Submitted by:{' '}
                                        <strong className="text-gray-800">{myRet.submitted_by?.name || 'User'}</strong>
                                    </div>
                                    <div>
                                        Submitted on:{' '}
                                        <strong className="text-gray-800">{formatDateTime(myRet.submitted_at)}</strong>
                                    </div>
                                    {myRet.comment && (
                                        <div className="w-full mt-1 bg-white/80 p-2 rounded border border-gray-200 italic text-gray-700">
                                            "{myRet.comment}"
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* All Departments Tracker Table */}
            <div className="card bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                <div className="p-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-gray-900 text-sm">
                            {isChampionOnly
                                ? "Your Department's Monthly Return Status"
                                : `Departmental Submission Status (${currentPeriodLabel})`}
                        </h3>
                        <p className="text-xs text-gray-500">
                            {isChampionOnly
                                ? "View submission status and loss declaration for your department"
                                : "Monitor which departments have reported losses or completed nil declarations"}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Status Filter */}
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="text-xs rounded-md border-gray-300 py-1.5 pl-2.5 pr-8 text-gray-700"
                        >
                            <option value="all">All Statuses ({returns.length})</option>
                            <option value="pending">Pending ({stats.pending})</option>
                            <option value="nil">Nil - No Loss ({stats.nil_reported})</option>
                            <option value="loss">Loss Reported ({stats.loss_reported})</option>
                        </select>

                        {/* Search Input */}
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Search department..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="text-xs rounded-md border-gray-300 py-1.5 pl-8 pr-3 text-gray-700 w-48 focus:w-64 transition-all"
                            />
                            <span className="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm">
                                search
                            </span>
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="data-table w-full">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Loss Events</th>
                                <th>Comment / Note</th>
                                <th>Submitted By</th>
                                <th>Date</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {filteredReturns.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <p className="font-medium text-gray-900 text-sm">
                                            {row.business_unit?.name}
                                        </p>
                                        <span className="font-mono text-xs text-gray-500">
                                            {row.business_unit?.code}
                                        </span>
                                    </td>
                                    <td>
                                        {row.status === 'submitted' ? (
                                            row.has_loss ? (
                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 border border-red-200">
                                                    Loss Reported
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    Nil Return (No Loss)
                                                </span>
                                            )
                                        ) : (
                                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                Pending
                                            </span>
                                        )}
                                    </td>
                                    <td className="tabular-nums text-sm font-medium">
                                        {row.loss_events_count > 0 ? (
                                            <span className="text-red-700 font-semibold">
                                                {row.loss_events_count} event(s)
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">0</span>
                                        )}
                                    </td>
                                    <td className="text-xs text-gray-600 max-w-xs truncate" title={row.comment || ''}>
                                        {row.comment ? (
                                            <span className="italic">"{row.comment}"</span>
                                        ) : (
                                            <span className="text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className="text-xs text-gray-600">
                                        {row.submitted_by?.name || <span className="text-gray-400">—</span>}
                                    </td>
                                    <td className="text-xs text-gray-500">
                                        {row.submitted_at ? formatDateTime(row.submitted_at) : '—'}
                                    </td>
                                    <td className="text-right">
                                        {row.status === 'pending' && can.send_reminder && (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    reminderForm.setData({
                                                        period_code: currentPeriod,
                                                        business_unit_id: row.business_unit_id,
                                                    });
                                                    setConfirmingReminder(true);
                                                }}
                                                className="text-xs text-amber-600 hover:text-amber-800 font-medium inline-flex items-center gap-1"
                                            >
                                                <span className="material-symbols-outlined text-sm">notifications</span>
                                                Remind
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {filteredReturns.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center py-8 text-gray-400 text-sm">
                                        No departments match your filter.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Modal: Nil Return Confirmation */}
            <Modal show={submittingReturn !== null} onClose={() => setSubmittingReturn(null)} maxWidth="md">
                <form onSubmit={submitNilReturn} className="p-6 space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600">
                            <span className="material-symbols-outlined text-xl">task_alt</span>
                        </div>
                        <div>
                            <h2 className="text-base font-semibold text-gray-900">
                                Submit Nil Return for {submittingReturn?.business_unit?.name}
                            </h2>
                            <p className="text-xs text-gray-500">
                                Period: {currentPeriodLabel}
                            </p>
                        </div>
                    </div>

                    <p className="text-sm text-gray-600">
                        Confirming that no operational loss event occurred in{' '}
                        <strong>{submittingReturn?.business_unit?.name}</strong> during {currentPeriodLabel}.
                    </p>

                    <div>
                        <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1">
                            Declaration Comment
                        </label>
                        <textarea
                            rows={3}
                            className="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                            value={nilForm.data.comment}
                            onChange={(e) => nilForm.setData('comment', e.target.value)}
                            placeholder="e.g. no loss was recorded"
                            required
                        />
                        <p className="text-xs text-gray-400 mt-1">
                            This comment is submitted to ORM to confirm zero loss events occurred in your department.
                        </p>
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setSubmittingReturn(null)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-medium shadow-sm transition"
                            disabled={nilForm.processing}
                        >
                            {nilForm.processing ? 'Submitting…' : 'Submit Nil Return'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Modal: Confirm Reminders */}
            <Modal show={confirmingReminder} onClose={() => setConfirmingReminder(false)} maxWidth="md">
                <form onSubmit={sendReminders} className="p-6 space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-full bg-amber-100 flex items-center justify-center text-amber-600">
                            <span className="material-symbols-outlined text-xl">notifications_active</span>
                        </div>
                        <div>
                            <h2 className="text-base font-semibold text-gray-900">
                                Send Monthly Loss Return Reminders
                            </h2>
                            <p className="text-xs text-gray-500">
                                Period: {currentPeriodLabel}
                            </p>
                        </div>
                    </div>

                    <p className="text-sm text-gray-600">
                        {reminderForm.data.business_unit_id
                            ? 'Send an immediate reminder notification to the designated Risk Champion for this department?'
                            : `Send reminder notifications to all ${stats.pending} pending departments for ${currentPeriodLabel}?`}
                    </p>

                    <div className="p-3 bg-gray-50 rounded-md text-xs text-gray-600 border border-gray-200">
                        Champions will receive an in-app and email reminder prompting them to submit their monthly loss
                        return or complete a nil declaration ("no loss was recorded").
                    </div>

                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            className="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50"
                            onClick={() => setConfirmingReminder(false)}
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-sm font-medium shadow-sm transition"
                            disabled={reminderForm.processing}
                        >
                            {reminderForm.processing ? 'Sending…' : 'Send Reminders Now'}
                        </button>
                    </div>
                </form>
            </Modal>
        </AppLayout>
    );
}
