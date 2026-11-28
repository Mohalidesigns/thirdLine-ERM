import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import { useGridState } from '@thirdline/ui/Components/DataGrid/useGridState';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Edge-anchored Create / Edit Process Group Slide-Over (matching AddRiskPanel pattern).
 */
function ProcessGroupSlideOver({ open, mode, group, options, onClose }) {
    const isEdit = mode === 'edit' && Boolean(group);
    const nameInputRef = useRef(null);

    const form = useForm({
        name: '',
        code: '',
        apqc_code: '',
        business_unit_id: '',
        owner_id: '',
        sort_order: 0,
        description: '',
        status: 'active',
    });

    // Reset & populate form whenever the slide-over opens
    useEffect(() => {
        if (!open) return;

        form.clearErrors();
        if (isEdit && group) {
            form.setData({
                name: group.name ?? '',
                code: group.code ?? '',
                apqc_code: group.apqc_code ?? '',
                business_unit_id: group.business_unit_id ? String(group.business_unit_id) : '',
                owner_id: group.owner_id ? String(group.owner_id) : '',
                sort_order: group.sort_order ?? 0,
                description: group.description ?? '',
                status: group.status ?? 'active',
            });
        } else {
            form.setData({
                name: '',
                code: '',
                apqc_code: '',
                business_unit_id: '',
                owner_id: '',
                sort_order: 0,
                description: '',
                status: 'active',
            });
        }

        const focusTimer = setTimeout(() => {
            nameInputRef.current?.focus();
        }, 50);

        return () => clearTimeout(focusTimer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, mode, group?.id]);

    // Close on Escape
    useEffect(() => {
        if (!open) return;
        const onKeyDown = (e) => {
            if (e.key === 'Escape' && !form.processing) {
                onClose();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, form.processing, onClose]);

    if (!open) return null;

    const handleSubmit = (e) => {
        e.preventDefault();

        form.transform((data) => ({
            name: data.name?.trim() ?? '',
            code: data.code?.trim() || null,
            apqc_code: data.apqc_code?.trim() || null,
            business_unit_id: data.business_unit_id ? Number(data.business_unit_id) : null,
            owner_id: data.owner_id ? Number(data.owner_id) : null,
            sort_order:
                data.sort_order === '' || data.sort_order === null
                    ? 0
                    : Number(data.sort_order),
            description: data.description?.trim() || null,
            status: data.status || 'active',
        }));

        const callbacks = {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
            },
        };

        if (isEdit && group) {
            form.put(route('risk.process-groups.update', group.id), callbacks);
        } else {
            form.post(route('risk.process-groups.store'), callbacks);
        }
    };

    const hasErrors = Object.keys(form.errors ?? {}).length > 0;

    return (
        <div
            className="fixed inset-0 z-40 flex justify-end bg-black/40"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pg-drawer-title"
        >
            <div
                className="fixed inset-0"
                aria-hidden="true"
                onClick={() => !form.processing && onClose()}
            />

            <div className="relative z-10 w-full max-w-lg bg-white h-full shadow-2xl flex flex-col">
                {/* Drawer Header */}
                <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-4">
                    <div>
                        <h2 id="pg-drawer-title" className="text-base font-bold text-[#1A365D]">
                            {isEdit ? `Edit ${group.code}` : 'New Process Group'}
                        </h2>
                        <p className="text-xs text-gray-500 mt-0.5">
                            Top-tier APQC PCF value-chain grouping for business processes.
                        </p>
                    </div>
                    <button
                        type="button"
                        disabled={form.processing}
                        onClick={onClose}
                        aria-label="Close process group drawer"
                        className="p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                    >
                        <span className="material-symbols-outlined text-xl">close</span>
                    </button>
                </div>

                {/* Form Body */}
                <form onSubmit={handleSubmit} className="flex-1 flex flex-col overflow-hidden">
                    <div className="flex-1 overflow-y-auto px-6 py-5 space-y-4">
                        {hasErrors && (
                            <div
                                role="alert"
                                className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-xs text-red-800"
                            >
                                Please correct the highlighted fields below.
                            </div>
                        )}

                        <div>
                            <label
                                htmlFor="pg-name"
                                className="block text-xs font-semibold text-gray-700 mb-1"
                            >
                                Group Name <span className="text-red-600">*</span>
                            </label>
                            <input
                                ref={nameInputRef}
                                id="pg-name"
                                type="text"
                                required
                                aria-required="true"
                                maxLength={255}
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="e.g. Payment Clearing & Settlement"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label
                                    htmlFor="pg-code"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    Code
                                </label>
                                <input
                                    id="pg-code"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)}
                                    placeholder={
                                        isEdit ? 'e.g. PG-001' : 'Auto-generated (e.g. PG-001)'
                                    }
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                                <InputError message={form.errors.code} className="mt-1" />
                            </div>

                            <div>
                                <label
                                    htmlFor="pg-apqc"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    APQC PCF Code (optional)
                                </label>
                                <input
                                    id="pg-apqc"
                                    type="text"
                                    maxLength={32}
                                    value={form.data.apqc_code}
                                    onChange={(e) => form.setData('apqc_code', e.target.value)}
                                    placeholder="e.g. 9.2"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                                <InputError message={form.errors.apqc_code} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <label
                                htmlFor="pg-department"
                                className="block text-xs font-semibold text-gray-700 mb-1"
                            >
                                Department / Business Unit (optional)
                            </label>
                            <select
                                id="pg-department"
                                value={form.data.business_unit_id}
                                onChange={(e) => form.setData('business_unit_id', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                            >
                                <option value="">
                                    Enterprise / Cross-Departmental (All Departments)
                                </option>
                                {(options.businessUnits ?? []).map((bu) => (
                                    <option key={bu.id} value={bu.id}>
                                        {bu.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.business_unit_id} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label
                                    htmlFor="pg-owner"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    Owner (optional)
                                </label>
                                <select
                                    id="pg-owner"
                                    value={form.data.owner_id}
                                    onChange={(e) => form.setData('owner_id', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">Unassigned</option>
                                    {(options.owners ?? []).map((owner) => (
                                        <option key={owner.id} value={owner.id}>
                                            {owner.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.owner_id} className="mt-1" />
                            </div>

                            <div>
                                <label
                                    htmlFor="pg-sort-order"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    Display Order
                                </label>
                                <input
                                    id="pg-sort-order"
                                    type="number"
                                    min={0}
                                    max={65535}
                                    value={form.data.sort_order}
                                    onChange={(e) => form.setData('sort_order', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm tabular-nums focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                                <InputError message={form.errors.sort_order} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <label
                                htmlFor="pg-description"
                                className="block text-xs font-semibold text-gray-700 mb-1"
                            >
                                Description (optional)
                            </label>
                            <textarea
                                id="pg-description"
                                rows={3}
                                maxLength={5000}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe the value chain or functional domain covered by this process group…"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                            />
                            <InputError message={form.errors.description} className="mt-1" />
                        </div>

                        {isEdit && (
                            <div>
                                <label
                                    htmlFor="pg-status"
                                    className="block text-xs font-semibold text-gray-700 mb-1"
                                >
                                    Status
                                </label>
                                <select
                                    id="pg-status"
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <InputError message={form.errors.status} className="mt-1" />
                            </div>
                        )}
                    </div>

                    {/* Drawer Footer */}
                    <div className="px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            disabled={form.processing}
                            onClick={onClose}
                            className="btn-secondary text-sm"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="btn-primary text-sm disabled:opacity-50"
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save Changes'
                                  : 'Create Group'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/**
 * Process Groups Admin Index (ADR 0028 Phase 2, process-groups-admin.md).
 *
 * Uses DataGrid + useGridState backed by App\Grids\Definitions\ProcessGroupGrid,
 * paired with an edge-anchored Create/Edit slide-over and Activate/Deactivate
 * management controls gated by `process_group.manage` (`can.manage`).
 */
export default function Index({
    counts = { active: 0, inactive: 0, total: 0 },
    grid,
    groups = [],
    options = { businessUnits: [], owners: [] },
    can = {},
}) {
    const gridState = useGridState(grid);
    const activeFilters = grid?.state?.filters ?? {};
    const currentStatus = activeFilters.status || '';
    const currentDepartment = activeFilters.business_unit_id || '';

    const [searchInput, setSearchInput] = useState(grid?.state?.search ?? '');
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [drawerMode, setDrawerMode] = useState('create');
    const [editingGroup, setEditingGroup] = useState(null);
    const [togglingId, setTogglingId] = useState(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        setSearchInput(grid?.state?.search ?? '');
    }, [grid?.state?.search]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        const serverSearch = grid?.state?.search ?? '';
        if (searchInput === serverSearch) return;

        const timer = setTimeout(() => {
            gridState.update({ search: searchInput });
        }, 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchInput]);

    const groupsById = useMemo(() => {
        const map = new Map();
        (groups ?? []).forEach((g) => map.set(Number(g.id), g));
        return map;
    }, [groups]);

    // Visible groups synced to current DataGrid page/filters
    const visibleManagedGroups = useMemo(() => {
        const gridRows = grid?.rows?.data ?? [];
        return gridRows
            .map((row) => groupsById.get(Number(row.id)))
            .filter(Boolean);
    }, [grid?.rows?.data, groupsById]);

    const openCreate = () => {
        setEditingGroup(null);
        setDrawerMode('create');
        setDrawerOpen(true);
    };

    const openEdit = (group) => {
        setEditingGroup(group);
        setDrawerMode('edit');
        setDrawerOpen(true);
    };

    const handleToggleGroup = (group) => {
        const isGroupActive = group.status === 'active';
        const count = group.processes_count ?? 0;
        const message = isGroupActive
            ? `Deactivate ${group.code} — ${group.name}? Its ${count} linked ${count === 1 ? 'process' : 'processes'} will remain active and unaffected, and this group will be hidden from new process selectors.`
            : `Reactivate ${group.code} — ${group.name}? It will reappear in process group selectors.`;

        if (!window.confirm(message)) {
            return;
        }

        setTogglingId(group.id);
        const endpoint = isGroupActive
            ? route('risk.process-groups.deactivate', group.id)
            : route('risk.process-groups.toggle-active', group.id);

        router.post(
            endpoint,
            {},
            {
                preserveScroll: true,
                onFinish: () => setTogglingId(null),
            },
        );
    };

    const hasActiveFilters = Boolean(
        currentStatus || currentDepartment || (grid?.state?.search ?? ''),
    );

    const catalogueUrl = tryRoute('risk.processes.index');
    const dashboardUrl = tryRoute('risk.processes.dashboard');

    const pill = (active) =>
        `px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
            active ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100'
        }`;

    const activeCount = counts.active ?? 0;
    const inactiveCount = counts.inactive ?? 0;
    const totalCount = counts.total ?? 0;
    const meta = grid?.rows?.meta ?? {};

    return (
        <AuthenticatedLayout title="Process Groups">
            <Head title="Process Groups" />

            <PageHeader
                title="Process Groups"
                subtitle={`${activeCount} active ${activeCount === 1 ? 'group' : 'groups'} · ${inactiveCount} inactive`}
                breadcrumbs={
                    dashboardUrl
                        ? [
                              { label: 'Processes', href: dashboardUrl },
                              { label: 'Process Groups' },
                          ]
                        : [{ label: 'Process Groups' }]
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {catalogueUrl && (
                            <Link
                                href={catalogueUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">list_alt</span>
                                Process Catalogue
                            </Link>
                        )}
                        {can.manage && (
                            <button
                                type="button"
                                onClick={openCreate}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">
                                    add_circle
                                </span>
                                New Process Group
                            </button>
                        )}
                    </div>
                }
            />

            {totalCount === 0 ? (
                <div className="bg-white rounded-xl border border-gray-200 p-8">
                    <EmptyState
                        icon="workspaces"
                        title="No process groups defined yet"
                        description="Group related business processes into value chains (e.g. Customer Onboarding & KYC, Payment Clearing & Settlement)."
                        actionLabel={can.manage ? 'New Process Group' : null}
                        onAction={can.manage ? openCreate : null}
                    />
                </div>
            ) : (
                <>
                    {/* Status Quick-Filter Pills */}
                    <div
                        role="group"
                        aria-label="Filter process groups by status"
                        className="flex gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit"
                    >
                        <button
                            type="button"
                            aria-pressed={currentStatus === 'active'}
                            onClick={() => gridState.update({ filters: { status: 'active' } })}
                            className={pill(currentStatus === 'active')}
                        >
                            Active ({activeCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentStatus === 'inactive'}
                            onClick={() => gridState.update({ filters: { status: 'inactive' } })}
                            className={pill(currentStatus === 'inactive')}
                        >
                            Inactive ({inactiveCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentStatus === ''}
                            onClick={() => gridState.update({ filters: { status: '' } })}
                            className={pill(currentStatus === '')}
                        >
                            All ({totalCount})
                        </button>
                    </div>

                    {/* Filter Bar */}
                    <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            <div>
                                <label
                                    htmlFor="pg-search"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Search
                                </label>
                                <input
                                    id="pg-search"
                                    type="search"
                                    value={searchInput}
                                    onChange={(e) => setSearchInput(e.target.value)}
                                    placeholder="Code, APQC code, or group name…"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="pg-filter-dept"
                                    className="block text-xs font-medium text-gray-600 mb-1"
                                >
                                    Department
                                </label>
                                <select
                                    id="pg-filter-dept"
                                    value={currentDepartment}
                                    onChange={(e) =>
                                        gridState.update({
                                            filters: { business_unit_id: e.target.value },
                                        })
                                    }
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                                >
                                    <option value="">All Departments</option>
                                    <option value="enterprise">
                                        Enterprise / Cross-Departmental
                                    </option>
                                    {(options.businessUnits ?? []).map((bu) => (
                                        <option key={bu.id} value={bu.id}>
                                            {bu.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="flex items-end">
                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearchInput('');
                                            gridState.clear();
                                        }}
                                        className="text-xs font-medium text-[#1A365D] hover:underline py-2"
                                    >
                                        Clear filters
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Screen-reader live region */}
                    <p className="sr-only" aria-live="polite">
                        {meta.total > 0
                            ? `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total} process groups`
                            : 'No process groups match the current filters.'}
                    </p>

                    {/* DataGrid backed by ProcessGroupGrid */}
                    <div
                        aria-busy={gridState.loading ? 'true' : undefined}
                        className={
                            gridState.loading
                                ? 'opacity-60 pointer-events-none transition-opacity'
                                : ''
                        }
                    >
                        <DataGrid grid={grid} />
                    </div>

                    {/* Manage Actions Panel for currently displayed groups (gated by process_group.manage) */}
                    {can.manage && visibleManagedGroups.length > 0 && (
                        <div className="mt-6 bg-white rounded-xl border border-gray-200 overflow-hidden">
                            <div className="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#1A365D]">
                                        Manage Process Groups
                                    </h2>
                                    <p className="text-xs text-gray-500">
                                        Edit group metadata or toggle active status for groups shown
                                        above
                                    </p>
                                </div>
                            </div>
                            <div className="divide-y divide-gray-100">
                                {visibleManagedGroups.map((group) => {
                                    const isGroupActive = group.status === 'active';
                                    const procCount = group.processes_count ?? 0;
                                    const filterHref =
                                        catalogueUrl && procCount > 0
                                            ? `${catalogueUrl}?filters[process_group_id]=${group.id}`
                                            : null;

                                    return (
                                        <div
                                            key={group.id}
                                            className="px-5 py-3 flex flex-wrap items-center justify-between gap-3 hover:bg-gray-50"
                                        >
                                            <div className="min-w-0 flex items-center gap-3">
                                                <span className="font-mono text-xs font-semibold text-[#1A365D]">
                                                    {group.code}
                                                </span>
                                                <span className="text-sm font-medium text-gray-900 truncate">
                                                    {group.name}
                                                </span>
                                                <StatusBadge status={group.status} />
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {filterHref && (
                                                    <Link
                                                        href={filterHref}
                                                        className="text-xs font-medium text-gray-600 hover:text-[#1A365D] hover:underline mr-2"
                                                    >
                                                        {procCount}{' '}
                                                        {procCount === 1 ? 'process' : 'processes'}
                                                    </Link>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => openEdit(group)}
                                                    className="btn-secondary text-xs py-1.5 px-3 inline-flex items-center gap-1"
                                                >
                                                    <span className="material-symbols-outlined text-sm">
                                                        edit
                                                    </span>
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={togglingId === group.id}
                                                    onClick={() => handleToggleGroup(group)}
                                                    className={`btn-secondary text-xs py-1.5 px-3 inline-flex items-center gap-1 disabled:opacity-50 ${
                                                        isGroupActive
                                                            ? 'text-red-700 border-red-200 hover:bg-red-50'
                                                            : 'text-[#2D7D46] border-green-200 hover:bg-green-50'
                                                    }`}
                                                >
                                                    <span className="material-symbols-outlined text-sm">
                                                        {isGroupActive
                                                            ? 'pause_circle'
                                                            : 'play_circle'}
                                                    </span>
                                                    {togglingId === group.id
                                                        ? 'Updating…'
                                                        : isGroupActive
                                                          ? 'Deactivate'
                                                          : 'Activate'}
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </>
            )}

            <ProcessGroupSlideOver
                open={drawerOpen}
                mode={drawerMode}
                group={editingGroup}
                options={options}
                onClose={() => setDrawerOpen(false)}
            />
        </AuthenticatedLayout>
    );
}
