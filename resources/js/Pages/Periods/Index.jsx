import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

/**
 * The reporting calendar (migration Phase 4.2: risk/periods/index.blade.php).
 *
 * Closing a period locks every value recorded in it and in the periods beneath
 * it, and then re-evaluates formula thresholds against the closed figures — so
 * it gets a real confirmation dialog rather than the `onsubmit="return
 * confirm(...)"` the Blade table used.
 *
 * Reopening keeps its mandatory reason. The Blade form enforced the ten
 * character floor with an Alpine handler AND an `alert()`; the server has
 * always enforced it too, and that is the copy shown here.
 */
export default function Index({ calendar = {}, periods = {}, type = 'quarter', types = {} }) {
    const [closing, setClosing] = useState(null);
    const [reopening, setReopening] = useState(null);

    const close = useForm({});
    const reopen = useForm({ reason: '' });

    const changeType = (next) =>
        router.get(route('risk.periods.index'), { type: next }, { preserveState: true, replace: true });

    const confirmClose = () => {
        close.post(route('risk.periods.close', closing.id), {
            preserveScroll: true,
            onFinish: () => setClosing(null),
        });
    };

    const submitReopen = (e) => {
        e.preventDefault();
        reopen.post(route('risk.periods.reopen', reopening.id), {
            preserveScroll: true,
            onSuccess: () => {
                reopen.reset();
                setReopening(null);
            },
        });
    };

    return (
        <AuthenticatedLayout title="Reporting Calendar">
            <Head title="Reporting Calendar" />

            <PageHeader
                title="Reporting Calendar"
                subtitle={`${calendar.name ?? 'Calendar'} · fiscal year starts in ${calendar.fiscalYearStartLabel ?? '—'}. Closing a period locks every value recorded in it and in the periods beneath it.`}
                breadcrumbs={[{ label: 'Governance' }, { label: 'Reporting Calendar' }]}
                actions={
                    <select
                        value={type}
                        onChange={(e) => changeType(e.target.value)}
                        className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700"
                    >
                        {Object.entries(types).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                }
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Code</th>
                                <th>Starts</th>
                                <th>Ends</th>
                                <th>Values recorded</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(periods.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-center py-12 text-sm text-gray-500">
                                        No periods of this granularity have been generated.
                                    </td>
                                </tr>
                            )}
                            {(periods.data ?? []).map((period) => (
                                <tr key={period.id} className="hover:bg-blue-50/50">
                                    <td className="font-medium text-[#1A365D]">
                                        <Link href={period.selectUrl} className="hover:underline">{period.name}</Link>
                                        {period.isSelected && <span className="badge bg-blue-100 text-blue-700 ml-1">selected</span>}
                                    </td>
                                    <td className="text-xs text-gray-500 font-mono">{period.code}</td>
                                    <td className="text-xs">{period.startDate}</td>
                                    <td className="text-xs">{period.endDate}</td>
                                    <td className="text-xs">{period.values.toLocaleString()}</td>
                                    <td>
                                        {period.isClosed ? (
                                            <>
                                                <span className="badge bg-gray-200 text-gray-700">Closed</span>
                                                <div className="text-[10px] text-gray-400 mt-0.5">
                                                    {period.closedAt}{period.closedBy ? ` by ${period.closedBy}` : ''}
                                                </div>
                                            </>
                                        ) : (
                                            <span className="badge bg-green-100 text-green-700">Open</span>
                                        )}
                                    </td>
                                    <td>
                                        {!period.isClosed && period.canClose && (
                                            <button type="button" onClick={() => setClosing(period)} className="text-xs text-[#1A365D] font-medium hover:underline">
                                                Close
                                            </button>
                                        )}
                                        {period.isClosed && period.canReopen && (
                                            <button type="button" onClick={() => setReopening(period)} className="text-xs text-red-600 font-medium hover:underline">
                                                Reopen
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination links={periods.links} meta={periods.meta} />
            </div>

            <ConfirmDialog
                show={closing !== null}
                title={`Close ${closing?.name ?? ''}?`}
                message={`Every value recorded in ${closing?.name ?? 'this period'} — ${(closing?.values ?? 0).toLocaleString()} of them — and in the periods beneath it will be locked. Formula thresholds are then re-evaluated against the closed figures, which may queue re-baselining approvals.`}
                confirmLabel="Close period"
                processing={close.processing}
                onConfirm={confirmClose}
                onCancel={() => setClosing(null)}
            />

            {reopening !== null && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <form onSubmit={submitReopen} className="bg-white rounded-xl shadow-xl max-w-lg w-full p-6">
                        <h2 className="text-lg font-semibold text-[#1A365D] mb-2">Reopen {reopening.name}?</h2>
                        <p className="text-sm text-gray-600 mb-4">
                            Reopening unlocks the values in this period. It can change a number a board pack has already
                            been built on, so the reason is recorded and read later.
                        </p>
                        <label className="block text-xs font-medium text-gray-600 mb-1">
                            Reason <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            rows={3}
                            maxLength={1000}
                            value={reopen.data.reason}
                            onChange={(e) => reopen.setData('reason', e.target.value)}
                            placeholder="At least 10 characters."
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        />
                        <InputError message={reopen.errors.reason} className="mt-1" />

                        <div className="flex justify-end gap-2 mt-4">
                            <button
                                type="button"
                                onClick={() => { reopen.reset(); reopen.clearErrors(); setReopening(null); }}
                                className="btn-secondary text-sm"
                            >
                                Cancel
                            </button>
                            <button type="submit" disabled={reopen.processing} className="px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-medium disabled:opacity-50">
                                Reopen period
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
