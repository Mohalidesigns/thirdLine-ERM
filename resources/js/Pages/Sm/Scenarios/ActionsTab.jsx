import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import Icon from '@thirdline/ui/Components/Icon';
import Modal from '@thirdline/ui/Components/Modal';

export default function ActionsTab({ scenario, actions, can, urls }) {
    const canManage = Boolean(actions?.can_manage_actions);
    const summary = actions?.summary || {
        total: 0,
        completed: 0,
        in_progress: 0,
        not_started: 0,
        overdue: 0,
        promoted_issues: 0,
    };
    const items = actions?.items || [];
    const businessUnits = actions?.businessUnits || [];
    const owners = actions?.owners || [];

    // Filter & view state
    const [activeSection, setActiveSection] = useState('all'); // 'all', 'gap_remediation', 'immediate'
    const [statusFilter, setStatusFilter] = useState('');
    const [searchQuery, setSearchQuery] = useState('');

    // Modal state
    const [modalOpen, setModalOpen] = useState(false);
    const [editingAction, setEditingAction] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [formErrors, setFormErrors] = useState({});

    // Promote modal state
    const [promoteAction, setPromoteAction] = useState(null);
    const [promotePriority, setPromotePriority] = useState('MEDIUM');
    const [promoting, setPromoting] = useState(false);

    // Delete confirmation state
    const [deleteAction, setDeleteAction] = useState(null);
    const [deleting, setDeleting] = useState(false);

    // Action form fields
    const defaultForm = {
        kind: 'gap_remediation',
        time_horizon: 'within_7_days',
        risk_area: '',
        current_position: '',
        identified_gap: '',
        action_plan: '',
        business_unit_id: '',
        department: '',
        owner_id: '',
        due_date: '',
        status: 'not_started',
        proof_of_closure: '',
    };
    const [formData, setFormData] = useState(defaultForm);

    const openCreateModal = (presetKind = 'gap_remediation', presetHorizon = 'within_7_days') => {
        setEditingAction(null);
        setFormData({
            ...defaultForm,
            kind: presetKind,
            time_horizon: presetHorizon,
        });
        setFormErrors({});
        setModalOpen(true);
    };

    const openEditModal = (action) => {
        setEditingAction(action);
        setFormData({
            kind: action.kind,
            time_horizon: action.time_horizon || 'within_7_days',
            risk_area: action.risk_area || '',
            current_position: action.current_position || '',
            identified_gap: action.identified_gap || '',
            action_plan: action.action_plan || '',
            business_unit_id: action.business_unit_id ? String(action.business_unit_id) : '',
            department: action.department || '',
            owner_id: action.owner_id ? String(action.owner_id) : '',
            due_date: action.due_date || '',
            status: action.status === 'overdue' ? 'in_progress' : action.status,
            proof_of_closure: action.proof_of_closure || '',
        });
        setFormErrors({});
        setModalOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if (submitting) return;

        setSubmitting(true);
        setFormErrors({});

        const payload = {
            kind: formData.kind,
            time_horizon: formData.kind === 'immediate' ? formData.time_horizon : null,
            risk_area: formData.risk_area,
            current_position: formData.current_position || null,
            identified_gap: formData.identified_gap || null,
            action_plan: formData.action_plan,
            business_unit_id: formData.business_unit_id ? Number(formData.business_unit_id) : null,
            department: formData.department || null,
            owner_id: formData.owner_id ? Number(formData.owner_id) : null,
            due_date: formData.due_date || null,
            status: formData.status,
            proof_of_closure: formData.proof_of_closure || null,
        };

        if (editingAction) {
            const updateUrl = route('scenario.actions.update', [scenario.id, editingAction.id]);
            router.put(updateUrl, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    setModalOpen(false);
                    setSubmitting(false);
                },
                onError: (errs) => {
                    setFormErrors(errs);
                    setSubmitting(false);
                },
            });
        } else {
            const storeUrl = route('scenario.actions.store', scenario.id);
            router.post(storeUrl, payload, {
                preserveScroll: true,
                onSuccess: () => {
                    setModalOpen(false);
                    setSubmitting(false);
                },
                onError: (errs) => {
                    setFormErrors(errs);
                    setSubmitting(false);
                },
            });
        }
    };

    const handlePromoteConfirm = () => {
        if (!promoteAction || promoting) return;
        setPromoting(true);

        const promoteUrl = route('scenario.actions.promote', [scenario.id, promoteAction.id]);
        router.post(promoteUrl, { priority: promotePriority }, {
            preserveScroll: true,
            onSuccess: () => {
                setPromoteAction(null);
                setPromoting(false);
            },
            onError: () => {
                setPromoting(false);
            },
        });
    };

    const handleDeleteConfirm = () => {
        if (!deleteAction || deleting) return;
        setDeleting(true);

        const deleteUrl = route('scenario.actions.destroy', [scenario.id, deleteAction.id]);
        router.delete(deleteUrl, {
            preserveScroll: true,
            onSuccess: () => {
                setDeleteAction(null);
                setDeleting(false);
            },
            onError: () => {
                setDeleting(false);
            },
        });
    };

    const filteredItems = items.filter((item) => {
        if (activeSection !== 'all' && item.kind !== activeSection) {
            return false;
        }
        if (statusFilter && item.status !== statusFilter) {
            return false;
        }
        if (searchQuery) {
            const q = searchQuery.toLowerCase();
            const textMatch =
                (item.risk_area || '').toLowerCase().includes(q) ||
                (item.action_plan || '').toLowerCase().includes(q) ||
                (item.identified_gap || '').toLowerCase().includes(q) ||
                (item.owner_name || '').toLowerCase().includes(q) ||
                (item.department || '').toLowerCase().includes(q);
            if (!textMatch) return false;
        }
        return true;
    });

    const gapActions = filteredItems.filter((i) => i.kind === 'gap_remediation');
    const immediateActions = filteredItems.filter((i) => i.kind === 'immediate');

    const getStatusBadge = (status) => {
        switch (status) {
            case 'completed':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">Completed</span>;
            case 'in_progress':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">In Progress</span>;
            case 'overdue':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">Overdue</span>;
            case 'not_started':
            default:
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">Not Started</span>;
        }
    };

    const getHorizonBadge = (horizon) => {
        switch (horizon) {
            case 'today':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 text-amber-900 border border-amber-300">Today</span>;
            case 'within_7_days':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-50 text-indigo-800 border border-indigo-200">Within 7 Days</span>;
            case 'within_30_days':
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 border border-purple-200">Within 30 Days</span>;
            case 'custom':
            default:
                return <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700">Custom</span>;
        }
    };

    return (
        <div className="space-y-6">
            {/* Header & Controls */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 className="text-xl font-bold text-gray-900">Remediation Actions & Immediate Plan</h2>
                    <p className="mt-1 text-sm text-gray-500">
                        Track identified capability gaps, resilience resolution plans, and immediate day-horizon response milestones.
                    </p>
                </div>
                {canManage && (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => openCreateModal('gap_remediation')}
                            className="btn-secondary text-sm flex items-center gap-1.5"
                        >
                            <Icon name="plus" className="w-4 h-4" />
                            Add Gap Action
                        </button>
                        <button
                            type="button"
                            onClick={() => openCreateModal('immediate')}
                            className="btn-primary text-sm flex items-center gap-1.5"
                        >
                            <Icon name="lightning-bolt" className="w-4 h-4" />
                            Add Immediate Plan
                        </button>
                    </div>
                )}
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Total Actions</div>
                    <div className="mt-1 text-2xl font-bold text-gray-900">{summary.total}</div>
                </div>
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Not Started</div>
                    <div className="mt-1 text-2xl font-bold text-gray-600">{summary.not_started}</div>
                </div>
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">In Progress</div>
                    <div className="mt-1 text-2xl font-bold text-blue-600">{summary.in_progress}</div>
                </div>
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Completed</div>
                    <div className="mt-1 text-2xl font-bold text-green-600">{summary.completed}</div>
                </div>
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Overdue</div>
                    <div className="mt-1 text-2xl font-bold text-red-600">{summary.overdue}</div>
                </div>
                <div className="bg-white p-3 rounded-lg border border-gray-200 shadow-sm">
                    <div className="text-xs font-medium text-gray-500 uppercase tracking-wider">Promoted Issues</div>
                    <div className="mt-1 text-2xl font-bold text-purple-600">{summary.promoted_issues}</div>
                </div>
            </div>

            {/* Filters Bar */}
            <div className="flex flex-col sm:flex-row items-center justify-between gap-3 bg-gray-50 p-3 rounded-lg border border-gray-200">
                <div className="flex items-center gap-2 w-full sm:w-auto">
                    <span className="text-xs font-semibold text-gray-600 uppercase">View:</span>
                    <div className="inline-flex rounded-md shadow-sm">
                        <button
                            type="button"
                            onClick={() => setActiveSection('all')}
                            className={`px-3 py-1.5 text-xs font-medium rounded-l-md border ${
                                activeSection === 'all'
                                    ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                            }`}
                        >
                            All ({items.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveSection('gap_remediation')}
                            className={`px-3 py-1.5 text-xs font-medium border-t border-b ${
                                activeSection === 'gap_remediation'
                                    ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                            }`}
                        >
                            Risk & Resolution Plan ({items.filter((i) => i.kind === 'gap_remediation').length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveSection('immediate')}
                            className={`px-3 py-1.5 text-xs font-medium rounded-r-md border ${
                                activeSection === 'immediate'
                                    ? 'bg-[var(--color-primary)] text-white border-[var(--color-primary)]'
                                    : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                            }`}
                        >
                            Immediate Roadmap ({items.filter((i) => i.kind === 'immediate').length})
                        </button>
                    </div>
                </div>

                <div className="flex items-center gap-3 w-full sm:w-auto">
                    <input
                        type="text"
                        placeholder="Search actions..."
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20 w-full sm:w-48"
                    />
                    <select
                        value={statusFilter}
                        onChange={(e) => setStatusFilter(e.target.value)}
                        className="text-xs rounded-md border-gray-300 focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                    >
                        <option value="">All Statuses</option>
                        <option value="not_started">Not Started</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
            </div>

            {/* Section 1: Risk & Resolution Plan (Gap Remediation) */}
            {(activeSection === 'all' || activeSection === 'gap_remediation') && (
                <div className="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 bg-gray-50/80 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900 flex items-center gap-2">
                                <span className="inline-block w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                                Possible Risk & Resolution Plan (Gap Remediation)
                            </h3>
                            <p className="mt-0.5 text-xs text-gray-500">
                                Current institutional posture, identified vulnerabilities, and strategic resolution points.
                            </p>
                        </div>
                        {canManage && (
                            <button
                                type="button"
                                onClick={() => openCreateModal('gap_remediation')}
                                className="text-xs text-[var(--color-primary)] hover:underline font-medium"
                            >
                                + Add Gap
                            </button>
                        )}
                    </div>

                    {gapActions.length === 0 ? (
                        <div className="p-8 text-center text-sm text-gray-500">
                            No gap remediation actions recorded for this scenario.
                            {canManage && (
                                <div className="mt-3">
                                    <button
                                        type="button"
                                        onClick={() => openCreateModal('gap_remediation')}
                                        className="btn-secondary text-xs"
                                    >
                                        Add First Gap Action
                                    </button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead className="bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">Risk Area</th>
                                        <th scope="col" className="px-4 py-3">Current Position (Where we are)</th>
                                        <th scope="col" className="px-4 py-3">Identified Gap (Vulnerability)</th>
                                        <th scope="col" className="px-4 py-3">Action Plan (Resolution)</th>
                                        <th scope="col" className="px-4 py-3">Owner / BU</th>
                                        <th scope="col" className="px-4 py-3">Due / Status</th>
                                        <th scope="col" className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {gapActions.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50/50">
                                            <td className="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">
                                                {item.risk_area}
                                                {item.issue_reference && (
                                                    <div className="mt-1">
                                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-800">
                                                            Issue #{item.issue_reference}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-600 max-w-xs break-words">
                                                {item.current_position || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-red-700 bg-red-50/30 max-w-xs break-words">
                                                {item.identified_gap || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-xs font-medium text-gray-800 max-w-xs break-words">
                                                {item.action_plan}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                                <div className="font-medium text-gray-800">{item.owner_name || 'Unassigned'}</div>
                                                <div className="text-gray-400">{item.business_unit_name || item.department || '—'}</div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex flex-col gap-1">
                                                    <div>{getStatusBadge(item.status)}</div>
                                                    <span className="text-[11px] text-gray-500">
                                                        Due: {item.due_date || 'No date'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap text-xs">
                                                <div className="flex items-center justify-end gap-2">
                                                    {canManage && (
                                                        <>
                                                            {!item.issue_id && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setPromoteAction(item)}
                                                                    className="text-purple-600 hover:text-purple-800 font-medium text-xs flex items-center gap-1"
                                                                    title="Promote to Enterprise Issue"
                                                                >
                                                                    <Icon name="arrow-up-circle" className="w-3.5 h-3.5" />
                                                                    Promote
                                                                </button>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => openEditModal(item)}
                                                                className="text-gray-600 hover:text-gray-900 font-medium"
                                                            >
                                                                Edit
                                                            </button>
                                                            {!item.issue_id && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setDeleteAction(item)}
                                                                    className="text-red-600 hover:text-red-800 font-medium"
                                                                >
                                                                    Delete
                                                                </button>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {/* Section 2: Immediate Action Roadmap */}
            {(activeSection === 'all' || activeSection === 'immediate') && (
                <div className="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 bg-gray-50/80 border-b border-gray-200 flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-semibold text-gray-900 flex items-center gap-2">
                                <span className="inline-block w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                                Immediate Action Roadmap (Horizon Milestones)
                            </h3>
                            <p className="mt-0.5 text-xs text-gray-500">
                                Tactical actions categorized by immediate execution horizon (Today, Within 7 Days, Within 30 Days).
                            </p>
                        </div>
                        {canManage && (
                            <button
                                type="button"
                                onClick={() => openCreateModal('immediate')}
                                className="text-xs text-[var(--color-primary)] hover:underline font-medium"
                            >
                                + Add Immediate Action
                            </button>
                        )}
                    </div>

                    {immediateActions.length === 0 ? (
                        <div className="p-8 text-center text-sm text-gray-500">
                            No immediate plan actions recorded for this scenario.
                            {canManage && (
                                <div className="mt-3">
                                    <button
                                        type="button"
                                        onClick={() => openCreateModal('immediate')}
                                        className="btn-secondary text-xs"
                                    >
                                        Add Immediate Plan Action
                                    </button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-left text-sm">
                                <thead className="bg-gray-50 text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    <tr>
                                        <th scope="col" className="px-4 py-3">Horizon</th>
                                        <th scope="col" className="px-4 py-3">Risk Focus / Title</th>
                                        <th scope="col" className="px-4 py-3">Action Description</th>
                                        <th scope="col" className="px-4 py-3">Department / Owner</th>
                                        <th scope="col" className="px-4 py-3">Due / Status</th>
                                        <th scope="col" className="px-4 py-3">Proof of Closure</th>
                                        <th scope="col" className="px-4 py-3 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {immediateActions.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50/50">
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                {getHorizonBadge(item.time_horizon)}
                                            </td>
                                            <td className="px-4 py-3 font-semibold text-gray-900 whitespace-nowrap">
                                                {item.risk_area}
                                                {item.issue_reference && (
                                                    <div className="mt-1">
                                                        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-800">
                                                            Issue #{item.issue_reference}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-xs font-medium text-gray-800 max-w-sm break-words">
                                                {item.action_plan}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                                <div className="font-medium text-gray-800">{item.owner_name || 'Unassigned'}</div>
                                                <div className="text-gray-400">{item.business_unit_name || item.department || '—'}</div>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">
                                                <div className="flex flex-col gap-1">
                                                    <div>{getStatusBadge(item.status)}</div>
                                                    <span className="text-[11px] text-gray-500">
                                                        Due: {item.due_date || 'No date'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-600 max-w-xs break-words">
                                                {item.proof_of_closure ? (
                                                    <span className="text-gray-700 bg-gray-50 px-2 py-1 rounded border border-gray-200 block text-[11px]">
                                                        {item.proof_of_closure}
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400 italic">None recorded</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right whitespace-nowrap text-xs">
                                                <div className="flex items-center justify-end gap-2">
                                                    {canManage && (
                                                        <>
                                                            {!item.issue_id && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setPromoteAction(item)}
                                                                    className="text-purple-600 hover:text-purple-800 font-medium text-xs flex items-center gap-1"
                                                                    title="Promote to Enterprise Issue"
                                                                >
                                                                    <Icon name="arrow-up-circle" className="w-3.5 h-3.5" />
                                                                    Promote
                                                                </button>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => openEditModal(item)}
                                                                className="text-gray-600 hover:text-gray-900 font-medium"
                                                            >
                                                                Edit
                                                            </button>
                                                            {!item.issue_id && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setDeleteAction(item)}
                                                                    className="text-red-600 hover:text-red-800 font-medium"
                                                                >
                                                                    Delete
                                                                </button>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}

            {/* Create / Edit Action Modal */}
            <Modal show={modalOpen} onClose={() => !submitting && setModalOpen(false)} maxWidth="2xl">
                <form onSubmit={handleFormSubmit} className="p-6">
                    <h3 className="text-lg font-bold text-gray-900">
                        {editingAction ? 'Edit Scenario Action' : 'Add Scenario Remediation Action'}
                    </h3>
                    <p className="mt-1 text-xs text-gray-500">
                        Record a risk & resolution gap or immediate plan horizon action for this scenario.
                    </p>

                    <div className="mt-5 space-y-4">
                        {/* Kind selection */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Action Kind <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={formData.kind}
                                    onChange={(e) => setFormData({ ...formData, kind: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                >
                                    <option value="gap_remediation">Gap Remediation (Risk & Resolution Plan)</option>
                                    <option value="immediate">Immediate Plan Action</option>
                                </select>
                            </div>

                            {formData.kind === 'immediate' && (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Time Horizon <span className="text-red-500">*</span>
                                    </label>
                                    <select
                                        value={formData.time_horizon}
                                        onChange={(e) => setFormData({ ...formData, time_horizon: e.target.value })}
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                    >
                                        <option value="today">Today</option>
                                        <option value="within_7_days">Within 7 Days</option>
                                        <option value="within_30_days">Within 30 Days</option>
                                        <option value="custom">Custom Date</option>
                                    </select>
                                </div>
                            )}
                        </div>

                        {/* Risk Area */}
                        <div>
                            <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Risk Area / Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                value={formData.risk_area}
                                onChange={(e) => setFormData({ ...formData, risk_area: e.target.value })}
                                placeholder="e.g. IT Disaster Recovery, Staff Succession, Facility Quarantine"
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                required
                            />
                            {formErrors.risk_area && (
                                <p className="mt-1 text-xs text-red-600">{formErrors.risk_area}</p>
                            )}
                        </div>

                        {/* Gap remediation specific fields */}
                        {formData.kind === 'gap_remediation' && (
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Current Position (Where we are)
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={formData.current_position}
                                        onChange={(e) => setFormData({ ...formData, current_position: e.target.value })}
                                        placeholder="Baseline operational status or existing controls..."
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                        Identified Gap (Vulnerability)
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={formData.identified_gap}
                                        onChange={(e) => setFormData({ ...formData, identified_gap: e.target.value })}
                                        placeholder="Control deficiency or exposure exposed by scenario..."
                                        className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                    />
                                </div>
                            </div>
                        )}

                        {/* Action Plan */}
                        <div>
                            <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Action Plan (Specific Resolution) <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                rows={3}
                                value={formData.action_plan}
                                onChange={(e) => setFormData({ ...formData, action_plan: e.target.value })}
                                placeholder="Clear, actionable remediation task..."
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                required
                            />
                            {formErrors.action_plan && (
                                <p className="mt-1 text-xs text-red-600">{formErrors.action_plan}</p>
                            )}
                        </div>

                        {/* Ownership & Department */}
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Action Owner
                                </label>
                                <select
                                    value={formData.owner_id}
                                    onChange={(e) => setFormData({ ...formData, owner_id: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                >
                                    <option value="">Select Owner</option>
                                    {owners.map((u) => (
                                        <option key={u.id} value={u.id}>{u.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Business Unit
                                </label>
                                <select
                                    value={formData.business_unit_id}
                                    onChange={(e) => setFormData({ ...formData, business_unit_id: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                >
                                    <option value="">Select BU</option>
                                    {businessUnits.map((bu) => (
                                        <option key={bu.id} value={bu.id}>{bu.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Department / Team
                                </label>
                                <input
                                    type="text"
                                    value={formData.department}
                                    onChange={(e) => setFormData({ ...formData, department: e.target.value })}
                                    placeholder="e.g. IT Operations"
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                />
                            </div>
                        </div>

                        {/* Due Date & Status */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Target Due Date
                                </label>
                                <input
                                    type="date"
                                    value={formData.due_date}
                                    onChange={(e) => setFormData({ ...formData, due_date: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                    Status
                                </label>
                                <select
                                    value={formData.status}
                                    onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                                    className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                                >
                                    <option value="not_started">Not Started</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                        </div>

                        {/* Proof of Closure */}
                        <div>
                            <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                                Proof of Closure / Verification
                            </label>
                            <textarea
                                rows={2}
                                value={formData.proof_of_closure}
                                onChange={(e) => setFormData({ ...formData, proof_of_closure: e.target.value })}
                                placeholder="Documentation, test sign-off, or link to artifact verifying completion..."
                                className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                            />
                        </div>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                        <button
                            type="button"
                            onClick={() => setModalOpen(false)}
                            disabled={submitting}
                            className="btn-secondary text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            className="btn-primary text-sm"
                        >
                            {submitting ? 'Saving...' : editingAction ? 'Update Action' : 'Create Action'}
                        </button>
                    </div>
                </form>
            </Modal>

            {/* Promote to Enterprise Issue Modal */}
            <Modal show={Boolean(promoteAction)} onClose={() => !promoting && setPromoteAction(null)} maxWidth="md">
                <div className="p-6">
                    <div className="flex items-center gap-3 text-purple-700">
                        <Icon name="arrow-up-circle" className="w-6 h-6" />
                        <h3 className="text-lg font-bold text-gray-900">Promote to Enterprise Issue</h3>
                    </div>

                    <p className="mt-2 text-sm text-gray-600">
                        Promoting this action elevates it from a scenario remediation note into a full registered{' '}
                        <strong>Enterprise Issue</strong> with an associated remediation task in the central risk register.
                    </p>

                    <div className="mt-4 p-3 bg-purple-50 rounded-lg border border-purple-100 text-xs text-purple-900">
                        <div className="font-semibold">{promoteAction?.risk_area}</div>
                        <div className="mt-1 text-purple-700 line-clamp-2">{promoteAction?.action_plan}</div>
                    </div>

                    <div className="mt-4">
                        <label className="block text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            Initial Issue Priority
                        </label>
                        <select
                            value={promotePriority}
                            onChange={(e) => setPromotePriority(e.target.value)}
                            className="mt-1 block w-full rounded-md border-gray-300 text-sm focus:border-[var(--color-primary)] focus:ring focus:ring-[var(--color-primary)]/20"
                        >
                            <option value="LOW">Low</option>
                            <option value="MEDIUM">Medium</option>
                            <option value="HIGH">High</option>
                            <option value="CRITICAL">Critical</option>
                        </select>
                    </div>

                    <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                        <button
                            type="button"
                            onClick={() => setPromoteAction(null)}
                            disabled={promoting}
                            className="btn-secondary text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handlePromoteConfirm}
                            disabled={promoting}
                            className="btn-primary bg-purple-600 hover:bg-purple-700 text-white text-sm"
                        >
                            {promoting ? 'Promoting...' : 'Confirm Promotion'}
                        </button>
                    </div>
                </div>
            </Modal>

            {/* Delete Confirmation Dialog */}
            <ConfirmDialog
                open={Boolean(deleteAction)}
                title="Delete Scenario Action"
                message={`Are you sure you want to delete the action "${deleteAction?.risk_area}"? This action cannot be undone.`}
                confirmLabel={deleting ? 'Deleting...' : 'Delete Action'}
                confirmVariant="danger"
                onConfirm={handleDeleteConfirm}
                onCancel={() => !deleting && setDeleteAction(null)}
            />
        </div>
    );
}

