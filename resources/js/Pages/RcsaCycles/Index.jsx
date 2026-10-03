import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import FormField from '@thirdline/ui/Components/FormField';
import FormSection from '@thirdline/ui/Components/FormSection';

/**
 * RCSA cycles — step 1 of the process flow.
 *
 * The create form warns when the universe has nothing published in it, because
 * that is the one way to schedule a cycle that cannot then be opened, and the
 * message a user would otherwise get comes only after they press the button.
 */
export default function Index({ cycles, filters = {}, options = {}, can = {} }) {
    const { flash } = usePage().props;
    const [creating, setCreating] = useState(false);

    const form = useForm({
        name: '',
        description: '',
        period_start: '',
        period_end: '',
        due_date: '',
        methodology_id: options.methodologies?.[0]?.id ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('rcsa.cycles.store'));
    };

    return (
        <AppLayout
            header={
                <PageHeader
                    title="RCSA Cycles"
                    subtitle="Schedule an assessment exercise and track how far each unit has got"
                    actions={
                        can.manage && (
                            <button type="button" onClick={() => setCreating(!creating)} className="btn-primary">
                                New cycle
                            </button>
                        )
                    }
                />
            }
        >
            <Head title="RCSA Cycles" />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
                    {flash.error}
                </div>
            )}

            {creating && (
                <form onSubmit={submit} className="mb-4">
                <FormSection title="New cycle">
                    {options.publishedRisks === 0 && (
                        <p className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            Nothing is published in the RCSA Universe yet, so this cycle would have no risks to
                            assess. Publish the universe rows first —{' '}
                            <Link href={route('rcsa.universe.index')} className="underline">
                                go to the universe
                            </Link>
                            .
                        </p>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <FormField label="Name" required error={form.errors.name}>
                            <input
                                className="form-input"
                                placeholder="RCSA 2026 H1"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </FormField>

                        <FormField
                            label="Methodology"
                            required
                            error={form.errors.methodology_id}
                            hint="Every line in this cycle is scored against this, and it stays fixed once the cycle opens."
                        >
                            <select
                                className="form-select"
                                value={form.data.methodology_id}
                                onChange={(e) => form.setData('methodology_id', e.target.value)}
                            >
                                {(options.methodologies ?? []).map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                        </FormField>

                        {[
                            ['period_start', 'Period start', true],
                            ['period_end', 'Period end', true],
                            ['due_date', 'Due date (optional)', false],
                        ].map(([field, label, required]) => (
                            <FormField key={field} label={label} required={required} error={form.errors[field]}>
                                <input
                                    type="date"
                                    className="form-input"
                                    value={form.data[field]}
                                    onChange={(e) => form.setData(field, e.target.value)}
                                />
                            </FormField>
                        ))}
                    </div>

                    <div className="form-actions">
                        <button type="button" onClick={() => setCreating(false)} className="btn-secondary text-sm">
                            Cancel
                        </button>
                        <button type="submit" disabled={form.processing} className="btn-primary text-sm">
                            Create as draft
                        </button>
                    </div>
                </FormSection>
                </form>
            )}

            <div className="filter-bar">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label">Status</label>
                        <select aria-label="Status"
                            className="filter-select"
                            value={filters.status ?? ''}
                            onChange={(e) =>
                                router.get(
                                    route('rcsa.cycles.index'),
                                    e.target.value ? { status: e.target.value } : {},
                                    { preserveState: true },
                                )
                            }
                        >
                            <option value="">All statuses</option>
                            {(options.statuses ?? []).map((s) => (
                                <option key={s} value={s}>
                                    {s.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Cycle</th>
                                <th>Period</th>
                                <th>Due</th>
                                <th>Assessments</th>
                                <th>Methodology</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(cycles?.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-center py-12">
                                        <div className="text-gray-400">
                                            <p className="text-sm font-medium">No cycles yet</p>
                                            <p className="text-xs mt-1">A cycle is the exercise an assessment belongs to</p>
                                        </div>
                                    </td>
                                </tr>
                            )}

                            {(cycles?.data ?? []).map((cycle) => (
                                <tr key={cycle.id}>
                                    <td>
                                        <Link
                                            href={route('rcsa.cycles.show', cycle.id)}
                                            className="cell-title"
                                        >
                                            {cycle.name}
                                        </Link>
                                        {cycle.description && (
                                            <p className="cell-subtitle">
                                                {cycle.description}
                                            </p>
                                        )}
                                    </td>
                                    <td className="cell-muted">
                                        {cycle.period_start} → {cycle.period_end}
                                    </td>
                                    <td className="cell-muted">{cycle.due_date ?? '—'}</td>
                                    <td className="cell-muted">{cycle.assessments_count}</td>
                                    <td className="cell-muted">{cycle.methodology ?? '—'}</td>
                                    <td>
                                        <StatusBadge status={cycle.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {cycles?.links?.length > 3 && (
                    <div className="border-t border-gray-100 px-4 py-3">
                        <Pagination links={cycles.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
