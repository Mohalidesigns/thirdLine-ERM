import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import Modal from '@thirdline/ui/Components/Modal';
import FormField from '@thirdline/ui/Components/FormField';
import { assessmentStatusLabel } from '@/lib/labels';
import { pluralise } from '@/lib/rcsa-format';
import useConfirm from '@/lib/useConfirm';

/**
 * An assessment counts as SUBMITTED once the business unit has handed it on:
 * `submitted`, or any state a submission can only reach from there. A unit
 * sitting in `bu_approval` or `returned` has not — it is back with somebody
 * on the business side (RCSA-16).
 */
const SUBMITTED = ['submitted', 'under_review', 'validated', 'closed'];

/**
 * The statuses an assessment can still be (re)assigned in. The server refuses
 * the WHOLE request if one id outside this set is included, so the page never
 * offers such a row: once a unit has been handed on (`bu_approval`,
 * `submitted`, `under_review`, `validated`, `closed`) its assessor is fixed.
 */
const ASSIGNABLE = ['draft', 'in_progress', 'returned'];
const isAssignable = (assessment) => ASSIGNABLE.includes(assessment.status);

/** The select's value for "take the assessor off", kept apart from '' (no choice yet). */
const UNASSIGNED = 'none';

/**
 * One cycle: the completion tracker of §10.3, and the button that opens it.
 *
 * OPENING SAYS WHAT IT WILL DO BEFORE IT DOES IT. It copies the whole published
 * universe into an assessment for every business unit and cannot be undone, so
 * the confirmation names that consequence rather than asking "are you sure?".
 *
 * ASSIGNING (RCSA-05). Who answers for a unit is chosen here, per row or for a
 * selection of rows, through one dialog. `assignees` and `can.assign` come from
 * the server; without them (an older backend) there is simply no Assign UI,
 * rather than a control that posts to nowhere.
 */
export default function Show({ cycle, assessments = [], assignees = [], can = {} }) {
    const { flash } = usePage().props;

    const canAssign = Boolean(can.assign) && cycle.status !== 'closed' && assessments.length > 0;

    const [selected, setSelected] = useState([]);
    const [dialog, setDialog] = useState(null); // { ids, unit, current } | null
    const [choice, setChoice] = useState('');
    const [errors, setErrors] = useState({});
    const [refusal, setRefusal] = useState(null); // the server's flash.error, shown inside the dialog
    const [processing, setProcessing] = useState(false);
    const selectAllRef = useRef(null);

    // A reload can drop a row (or the cycle closes under us): never act on an id the page no longer shows.
    const selectable = useMemo(() => assessments.filter(isAssignable), [assessments]);
    const liveSelected = useMemo(
        () => selected.filter((id) => selectable.some((a) => a.id === id)),
        [selected, selectable],
    );
    const allSelected = selectable.length > 0 && liveSelected.length === selectable.length;

    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = liveSelected.length > 0 && !allSelected;
        }
    }, [liveSelected.length, allSelected]);

    const toggle = (id) =>
        setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const toggleAll = () => setSelected(allSelected ? [] : selectable.map((a) => a.id));

    const openDialog = (ids, unit, current) => {
        setErrors({});
        setRefusal(null);
        setChoice(current ? String(current) : '');
        setDialog({ ids, unit, current });
    };

    const closeDialog = () => {
        if (!processing) setDialog(null);
    };

    const submitAssign = (event) => {
        event.preventDefault();

        if (choice === '' || processing) return;

        router.post(
            route('rcsa.cycles.assign', cycle.id),
            {
                assessment_ids: dialog.ids,
                assigned_to: choice === UNASSIGNED ? null : Number(choice),
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    setRefusal(null);
                },
                onError: (bag) => setErrors(bag),
                onSuccess: (page) => {
                    // A refusal (a unit moved on since the page loaded) comes back as a
                    // flash on a successful visit, not as a validation bag: keep the
                    // dialog open and say so there, rather than closing on the failure.
                    const failure = page?.props?.flash?.error;

                    if (failure) {
                        setRefusal(failure);
                        return;
                    }

                    setDialog(null);
                    setSelected([]);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const [confirm, confirmDialog] = useConfirm();

    const open = () =>
        confirm({
            title: `Open ${cycle.name}?`,
            message:
                'This copies every published risk in the RCSA Universe into an assessment for each business unit. It cannot be undone — risks added to the universe afterwards belong to the next cycle.',
            confirmLabel: 'Open cycle',
            onConfirm: () => router.post(route('rcsa.cycles.open', cycle.id), {}, { preserveScroll: true }),
        });

    const close = () =>
        confirm({
            title: `Close ${cycle.name}?`,
            message: 'Every assessment under it becomes read-only, including any a unit has not finished.',
            confirmLabel: 'Close cycle',
            variant: 'warning',
            onConfirm: () => router.post(route('rcsa.cycles.close', cycle.id), {}, { preserveScroll: true }),
        });

    const submitted = assessments.filter((a) => SUBMITTED.includes(a.status)).length;
    const notStarted = assessments.filter((a) => a.status === 'draft').length;

    return (
        <AppLayout
            header={
                <PageHeader
                    title={cycle.name}
                    subtitle={`${cycle.period_start} → ${cycle.period_end}${cycle.due_date ? ` · due ${cycle.due_date}` : ''}`}
                    breadcrumbs={[{ label: 'RCSA cycles', href: route('rcsa.cycles.index') }, { label: cycle.name }]}
                    actions={
                        <div className="flex items-center gap-2">
                            <StatusBadge status={cycle.status} />
                            {cycle.status === 'draft' && can.open && (
                                <button type="button" onClick={open} className="btn-primary">
                                    Open cycle
                                </button>
                            )}
                            {cycle.status === 'open' && can.close && (
                                <button type="button" onClick={close} className="btn-secondary">
                                    Close cycle
                                </button>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={cycle.name} />

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

            {cycle.status === 'draft' && (
                <div className="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                    This cycle is a draft. Opening it copies every <strong>published</strong> risk in the universe
                    into an assessment for each business unit — that is what fills in the process, risk and control
                    columns so an assessor only answers likelihood, impact and control effectiveness.
                </div>
            )}

            {assessments.length > 0 && (
                <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    <Tile label="Business units" value={assessments.length} />
                    <Tile label="Risks in scope" value={assessments.reduce((n, a) => n + a.lines_count, 0)} />
                    <Tile label="Not started" value={`${notStarted} of ${assessments.length}`} />
                    <Tile label="Units submitted" value={`${submitted} of ${assessments.length}`} />
                    <Tile
                        label="Average progress"
                        value={`${Math.round(assessments.reduce((n, a) => n + a.completion_pct, 0) / assessments.length)}%`}
                    />
                </div>
            )}

            {canAssign && liveSelected.length > 0 && (
                <div
                    role="region"
                    aria-label="Bulk actions"
                    className="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-900"
                >
                    <span>{pluralise(liveSelected.length, 'unit')} selected</span>
                    <button
                        type="button"
                        className="btn-primary text-sm"
                        onClick={() => openDialog(liveSelected, null, null)}
                    >
                        Assign selected
                    </button>
                    <button type="button" className="btn-secondary text-sm" onClick={() => setSelected([])}>
                        Clear selection
                    </button>
                </div>
            )}

            <div className="card">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                {canAssign && (
                                    <th className="w-10">
                                        <input
                                            ref={selectAllRef}
                                            type="checkbox"
                                            className="form-checkbox"
                                            aria-label="Select all business units that can still be assigned"
                                            title={
                                                selectable.length === 0
                                                    ? 'No business unit can be assigned: every assessment has been handed on'
                                                    : undefined
                                            }
                                            checked={allSelected}
                                            disabled={selectable.length === 0}
                                            onChange={toggleAll}
                                        />
                                    </th>
                                )}
                                <th>Business unit</th>
                                <th>Risks</th>
                                <th className="w-48">Progress</th>
                                <th>Assigned to</th>
                                <th>Status</th>
                                <th className="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {assessments.length === 0 && (
                                <tr>
                                    <td colSpan={canAssign ? 7 : 6} className="text-center py-12">
                                        <div className="text-gray-400">
                                            <p className="text-sm font-medium">Nothing provisioned yet</p>
                                            <p className="text-xs mt-1">Open the cycle to create the assessments</p>
                                        </div>
                                    </td>
                                </tr>
                            )}

                            {assessments.map((assessment) => (
                                <tr key={assessment.id}>
                                    {canAssign && (
                                        <td>
                                            {isAssignable(assessment) ? (
                                                <input
                                                    type="checkbox"
                                                    className="form-checkbox"
                                                    aria-label={`Select ${assessment.business_unit}`}
                                                    checked={liveSelected.includes(assessment.id)}
                                                    onChange={() => toggle(assessment.id)}
                                                />
                                            ) : (
                                                <input
                                                    type="checkbox"
                                                    className="form-checkbox"
                                                    disabled
                                                    checked={false}
                                                    readOnly
                                                    aria-label={`${assessment.business_unit} cannot be assigned: it is ${assessmentStatusLabel(assessment.status).toLowerCase()}`}
                                                    title={`Cannot be assigned: it is ${assessmentStatusLabel(assessment.status).toLowerCase()}`}
                                                />
                                            )}
                                        </td>
                                    )}
                                    <td className="text-sm font-medium text-gray-700">{assessment.business_unit}</td>
                                    <td className="cell-muted">{assessment.lines_count}</td>
                                    <td>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 w-24 rounded-full bg-gray-100">
                                                <div
                                                    className="h-2 rounded-full bg-[var(--color-primary)]"
                                                    style={{ width: `${assessment.completion_pct}%` }}
                                                />
                                            </div>
                                            <span className="text-xs text-gray-600">{assessment.completion_pct}%</span>
                                        </div>
                                    </td>
                                    <td className="cell-muted">{assessment.assignee ?? 'Unassigned'}</td>
                                    <td>
                                        <StatusBadge
                                            status={assessment.status}
                                            label={assessmentStatusLabel(assessment.status)}
                                        />
                                    </td>
                                    <td className="text-right">
                                        <div className="row-actions">
                                            {canAssign && isAssignable(assessment) && (
                                                <button
                                                    type="button"
                                                    className="mr-3 text-sm font-medium text-[var(--color-primary)] hover:underline"
                                                    aria-label={`${assessment.assignee ? 'Reassign' : 'Assign'} ${assessment.business_unit}`}
                                                    onClick={() =>
                                                        openDialog(
                                                            [assessment.id],
                                                            assessment.business_unit,
                                                            assessment.assignee_id ?? null,
                                                        )
                                                    }
                                                >
                                                    {assessment.assignee ? 'Reassign' : 'Assign'}
                                                </button>
                                            )}
                                            <Link
                                                href={route('rcsa.assessments.show', assessment.id)}
                                                className="row-action"
                                                aria-label="Open" title="Open"
                                            >
                                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            </Link>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal show={dialog !== null} maxWidth="md" onClose={closeDialog}>
                {dialog && (
                    <form onSubmit={submitAssign} className="p-6">
                        <h3 className="text-lg font-semibold text-gray-900">
                            {dialog.ids.length === 1 && dialog.unit
                                ? `Assign ${dialog.unit}`
                                : `Assign ${pluralise(dialog.ids.length, 'unit')}`}
                        </h3>
                        <p className="mt-1 text-sm text-gray-500">
                            The assessor is told by notification and finds the assessment under their own list.
                            Choose Unassigned to take the current assessor off.
                        </p>

                        <div className="mt-4">
                            <FormField label="Assessor" required error={errors.assigned_to}>
                                <select
                                    className="form-select"
                                    value={choice}
                                    onChange={(e) => setChoice(e.target.value)}
                                    disabled={processing}
                                >
                                    <option value="" disabled>
                                        Choose an assessor…
                                    </option>
                                    <option value={UNASSIGNED}>Unassigned</option>
                                    {assignees.map((person) => (
                                        <option key={person.id} value={person.id}>
                                            {person.name}
                                        </option>
                                    ))}
                                </select>
                            </FormField>
                            {assignees.length === 0 && (
                                <p className="mt-2 text-xs text-gray-500">
                                    No users are available to assign. Unassigned is the only choice.
                                </p>
                            )}
                            {refusal && (
                                <p role="alert" className="mt-2 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                                    {refusal}
                                </p>
                            )}
                            {errors.assessment_ids && (
                                <p role="alert" className="mt-2 text-sm text-red-600">
                                    {errors.assessment_ids}
                                </p>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button type="button" className="btn-secondary text-sm" onClick={closeDialog} disabled={processing}>
                                Cancel
                            </button>
                            <button
                                type="submit"
                                className="btn-primary text-sm disabled:cursor-not-allowed disabled:opacity-50"
                                disabled={processing || choice === ''}
                            >
                                {processing ? 'Assigning…' : 'Assign'}
                            </button>
                        </div>
                    </form>
                )}
            </Modal>
            {confirmDialog}
        </AppLayout>
    );
}

function Tile({ label, value }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">{label}</p>
            <p className="mt-1 text-2xl font-semibold text-gray-800">{value}</p>
        </div>
    );
}
