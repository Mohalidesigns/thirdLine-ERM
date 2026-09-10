import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import FilterBar from '@thirdline/ui/Components/FilterBar';
import Modal from '@thirdline/ui/Components/Modal';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const humanise = (value) => String(value).replaceAll('_', ' ');

/**
 * The filing calendar as a register (migration Phase 5.3).
 *
 * Filing against a deadline is its own permission — `regulatory.file`, not
 * `regulatory.manage` — because recording that a return went to the CBN on a
 * given date is a statement to a supervisor, while adding a deadline to the
 * calendar is administration. RegulatoryDeadlinePolicy enforces the split the
 * routes have always described.
 */
export default function Index({ deadlines, filters, regulators, statuses, canFile }) {
    const [filing, setFiling] = useState(null);

    const form = useForm({ filing_date: new Date().toISOString().slice(0, 10), document_ref: '', notes: '' });

    const submitFiling = (event) => {
        event.preventDefault();

        form.post(route('risk.regulatory.submit-filing', filing.id), {
            preserveScroll: true,
            onSuccess: () => {
                setFiling(null);
                form.reset();
            },
        });
    };

    const createUrl = tryRoute('risk.regulatory.create-deadline');

    return (
        <AuthenticatedLayout title="Regulatory Deadlines">
            <Head title="Regulatory Deadlines" />

            <PageHeader
                title="Regulatory Deadlines"
                subtitle="What is due to which regulator, and who is accountable for it"
                actions={
                    createUrl && (
                        <Link href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">add</span> New deadline
                        </Link>
                    )
                }
            />

            <FilterBar
                route={route('risk.regulatory.deadlines')}
                currentFilters={filters}
                filters={[
                    {
                        name: 'regulator',
                        type: 'select',
                        label: 'Regulator',
                        options: regulators.map((r) => ({ value: r, label: r })),
                    },
                    {
                        name: 'status',
                        type: 'select',
                        label: 'Status',
                        options: statuses.map((s) => ({ value: s, label: humanise(s) })),
                    },
                ]}
            />

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                {deadlines.data.length === 0 ? (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">event</span>}
                        title="No deadlines on the calendar"
                        description="Add the returns this institution owes its regulators, and who is accountable for each."
                        actionLabel={createUrl ? 'Add a deadline' : undefined}
                        actionHref={createUrl ?? undefined}
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Due</th>
                                    <th>Regulator</th>
                                    <th>Return</th>
                                    <th>Frequency</th>
                                    <th>Responsible</th>
                                    <th>Status</th>
                                    {canFile && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {deadlines.data.map((deadline) => (
                                    <tr key={deadline.id} className={deadline.is_overdue ? 'bg-red-50/50' : ''}>
                                        <td className="text-xs">
                                            {shortDate(deadline.deadline_date)}
                                            {deadline.is_overdue && (
                                                <span className="ml-2 badge bg-red-100 text-red-700 text-[10px]">Overdue</span>
                                            )}
                                        </td>
                                        <td className="text-xs">{deadline.regulator}</td>
                                        <td className="font-medium text-[#1A365D]">
                                            {deadline.title}
                                            <span className="block text-xs font-normal text-gray-500">{deadline.report_type}</span>
                                        </td>
                                        <td className="text-xs">{humanise(deadline.frequency)}</td>
                                        <td className="text-xs">{deadline.responsible?.name ?? '—'}</td>
                                        <td>
                                            <StatusBadge status={deadline.status} />
                                        </td>
                                        {canFile && (
                                            <td>
                                                {deadline.status !== 'submitted' && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setFiling(deadline)}
                                                        className="text-xs text-[#1A365D] font-medium hover:underline"
                                                    >
                                                        Record filing
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <Pagination links={deadlines.links} />
            </div>

            <Modal show={filing !== null} onClose={() => setFiling(null)} maxWidth="md">
                <form onSubmit={submitFiling} className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-[#1A365D]">Record a filing</h2>
                    <p className="text-sm text-gray-500">
                        {filing?.regulator} &middot; {filing?.title}
                    </p>

                    <div>
                        <InputLabel htmlFor="filing_date" value="Filing date" />
                        <TextInput
                            id="filing_date"
                            type="date"
                            className="mt-1 block w-full"
                            value={form.data.filing_date}
                            onChange={(e) => form.setData('filing_date', e.target.value)}
                            required
                        />
                        <InputError message={form.errors.filing_date} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="document_ref" value="Document reference (optional)" />
                        <TextInput
                            id="document_ref"
                            className="mt-1 block w-full"
                            value={form.data.document_ref}
                            onChange={(e) => form.setData('document_ref', e.target.value)}
                        />
                        <InputError message={form.errors.document_ref} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="notes" value="Notes (optional)" />
                        <textarea
                            id="notes"
                            rows={3}
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                        />
                        <InputError message={form.errors.notes} className="mt-1" />
                    </div>

                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setFiling(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Record filing</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
