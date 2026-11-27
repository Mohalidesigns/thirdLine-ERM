import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import { useGridState } from '@thirdline/ui/Components/DataGrid/useGridState';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Process Catalogue Index (ADR 0028 Phase 2, process-dashboard-and-catalogue.md).
 *
 * Uses DataGrid + useGridState backed by App\Grids\Definitions\ProcessGrid,
 * paired with hierarchy & risk-coverage quick-filter pills, Process Group /
 * Department / Owner / Criticality / Status filters, and filtered CSV/PDF exports.
 */
export default function Index({
    counts = { processes: 0, sub_processes: 0, unmapped: 0 },
    grid,
    can = {},
}) {
    const gridState = useGridState(grid);
    const activeFilters = grid?.state?.filters ?? {};

    const currentLevel = activeFilters.level || '';
    const currentGroup = activeFilters.process_group_id || '';
    const currentDepartment = activeFilters.business_unit_id || '';
    const currentOwner = activeFilters.owner_id || '';
    const currentCriticality = activeFilters.criticality || '';
    const currentHasRisks = activeFilters.has_risks || '';
    const currentStatus = activeFilters.is_active ?? '';

    const hasActiveFilters = Boolean(
        currentLevel ||
            currentGroup ||
            currentDepartment ||
            currentOwner ||
            currentCriticality ||
            currentHasRisks ||
            currentStatus !== '' ||
            (grid?.state?.search ?? ''),
    );

    const exportQueryString = useMemo(() => {
        const params = new URLSearchParams();
        if (currentLevel) params.set('filters[level]', currentLevel);
        if (currentGroup) params.set('filters[process_group_id]', currentGroup);
        if (currentDepartment) params.set('filters[business_unit_id]', currentDepartment);
        if (currentOwner) params.set('filters[owner_id]', currentOwner);
        if (currentCriticality) params.set('filters[criticality]', currentCriticality);
        if (currentHasRisks) params.set('filters[has_risks]', currentHasRisks);
        if (currentStatus !== '') params.set('filters[is_active]', String(currentStatus));
        if (grid?.state?.search) params.set('search', grid.state.search);
        const qs = params.toString();
        return qs ? `?${qs}` : '';
    }, [
        currentLevel,
        currentGroup,
        currentDepartment,
        currentOwner,
        currentCriticality,
        currentHasRisks,
        currentStatus,
        grid?.state?.search,
    ]);

    const dashboardUrl = tryRoute('risk.processes.dashboard');
    const groupsUrl = tryRoute('risk.process-groups.index');
    const createUrl = tryRoute('risk.processes.create');
    const exportCsvUrl = tryRoute('risk.processes.export.csv');
    const exportPdfUrl = tryRoute('risk.processes.export.pdf');

    const pill = (active) =>
        `px-3 py-1.5 text-xs font-medium rounded-md transition-colors ${
            active ? 'bg-[#1A365D] text-white' : 'text-gray-600 hover:bg-gray-100'
        }`;

    const processesCount = counts.processes ?? 0;
    const subProcessesCount = counts.sub_processes ?? 0;
    const unmappedCount = counts.unmapped ?? 0;
    const meta = grid?.rows?.meta ?? {};
    const totalCatalogueRecords = processesCount + subProcessesCount;
    const isTrulyEmpty =
        totalCatalogueRecords === 0 && !hasActiveFilters && (meta.total ?? 0) === 0;

    return (
        <AuthenticatedLayout title="Process Catalogue">
            <Head title="Process Catalogue" />

            <PageHeader
                title="Process Catalogue"
                subtitle={`${processesCount} ${processesCount === 1 ? 'process' : 'processes'} · ${subProcessesCount} ${subProcessesCount === 1 ? 'sub-process' : 'sub-processes'}`}
                breadcrumbs={
                    dashboardUrl
                        ? [
                              { label: 'Process Dashboard', href: dashboardUrl },
                              { label: 'Process Catalogue' },
                          ]
                        : [{ label: 'Process Catalogue' }]
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {exportCsvUrl && (
                            <a
                                href={`${exportCsvUrl}${exportQueryString}`}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">download</span>
                                Export CSV
                            </a>
                        )}
                        {exportPdfUrl && (
                            <a
                                href={`${exportPdfUrl}${exportQueryString}`}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">picture_as_pdf</span>
                                Export PDF
                            </a>
                        )}
                        {groupsUrl && (
                            <Link
                                href={groupsUrl}
                                className="btn-secondary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">workspaces</span>
                                Process Groups
                            </Link>
                        )}
                        {can.create && createUrl && (
                            <Link
                                href={createUrl}
                                className="btn-primary text-sm inline-flex items-center gap-1.5"
                            >
                                <span className="material-symbols-outlined text-lg">add_circle</span>
                                New Process
                            </Link>
                        )}
                    </div>
                }
            />

            {isTrulyEmpty ? (
                <div className="bg-white rounded-xl border border-gray-200 p-8">
                    <EmptyState
                        icon="schema"
                        title="No business processes registered yet"
                        description={
                            can.create
                                ? 'Create your first business process to establish your APQC-aligned operational hierarchy.'
                                : 'Contact your Risk Manager to register business processes.'
                        }
                        actionLabel={can.create && createUrl ? 'New Process' : null}
                        actionHref={can.create ? createUrl : null}
                    />
                </div>
            ) : (
                <>
                    {/* Quick-Filter Pills */}
                    <div
                        role="group"
                        aria-label="Filter by process level and risk coverage"
                        className="flex flex-wrap gap-1 mb-4 bg-white rounded-lg border border-gray-200 p-1 w-fit"
                    >
                        <button
                            type="button"
                            aria-pressed={currentLevel === 'process' && currentHasRisks === ''}
                            onClick={() =>
                                gridState.update({ filters: { level: 'process', has_risks: '' } })
                            }
                            className={pill(currentLevel === 'process' && currentHasRisks === '')}
                        >
                            Top-Level Processes ({processesCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentLevel === 'sub_process' && currentHasRisks === ''}
                            onClick={() =>
                                gridState.update({ filters: { level: 'sub_process', has_risks: '' } })
                            }
                            className={pill(currentLevel === 'sub_process' && currentHasRisks === '')}
                        >
                            Sub-Processes ({subProcessesCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentHasRisks === 'none'}
                            onClick={() =>
                                gridState.update({ filters: { level: 'process', has_risks: 'none' } })
                            }
                            className={pill(currentHasRisks === 'none')}
                        >
                            No Linked Risks ({unmappedCount})
                        </button>
                        <button
                            type="button"
                            aria-pressed={currentLevel === '' && currentHasRisks === ''}
                            onClick={() =>
                                gridState.update({ filters: { level: '', has_risks: '' } })
                            }
                            className={pill(currentLevel === '' && currentHasRisks === '')}
                        >
                            All
                        </button>
                    </div>

                    {/* Screen-reader live region */}
                    <p className="sr-only" aria-live="polite">
                        {meta.total > 0
                            ? `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total} processes`
                            : 'No processes match the current filters.'}
                    </p>

                    {/* DataGrid */}
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
                </>
            )}
        </AuthenticatedLayout>
    );
}
