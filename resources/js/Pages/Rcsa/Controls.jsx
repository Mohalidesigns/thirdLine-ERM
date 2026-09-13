import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Pagination from '@thirdline/ui/Components/Pagination';

const EFFECTIVENESS = {
    effective: { label: 'Effective', class: 'bg-green-100 text-green-700' },
    partially_effective: { label: 'Partially effective', class: 'bg-yellow-100 text-yellow-700' },
    ineffective: { label: 'Ineffective', class: 'bg-red-100 text-red-700' },
    not_tested: { label: 'Not tested', class: 'bg-gray-100 text-gray-600' },
};

const TYPES = ['preventive', 'detective', 'corrective', 'directive'];

function titleCase(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ') : '-';
}

/**
 * Control effectiveness (migration Phase 3.8: risk/rcsa/controls.blade.php).
 *
 * THE COLUMNS ARE NOT THE BLADE PAGE'S COLUMNS, and that is the point. That
 * table asked a Control for `type`, `risk`, `design_effectiveness`,
 * `operating_effectiveness`, `overall_rating`, `issues_count`, `assessor` and
 * `assessed_at`. The controls table has NONE of them — every one was rendered
 * through `?? '-'` or `?? 0`, so seven of its nine columns printed a dash for
 * every control on every tenant, forever. The columns here are the ones the
 * model actually has. See the module notes.
 */
export default function Controls({ summary = {}, controls = {}, businessUnits = [], filters = {} }) {
    const apply = (patch) =>
        router.get(route('risk.rcsa.controls'), { ...filters, ...patch }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const select = (name, value, options, placeholder) => (
        <select
            value={filters[name] ?? ''}
            onChange={(e) => apply({ [name]: e.target.value || null })}
            className="border border-gray-300 rounded-lg px-3 py-2 text-sm text-gray-700"
        >
            <option value="">{placeholder}</option>
            {options}
        </select>
    );

    return (
        <AuthenticatedLayout title="Control Effectiveness">
            <Head title="Control Effectiveness" />

            <PageHeader
                title="Control Effectiveness Assessment"
                subtitle="Evaluate the design and operating effectiveness of controls"
                breadcrumbs={[{ label: 'RCSA', href: route('risk.rcsa.dashboard') }, { label: 'Control Effectiveness' }]}
            />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard title="Total Controls" value={summary.total ?? 0} icon="verified_user" color="primary" />
                <KpiCard title="Effective" value={summary.effective ?? 0} icon="check_circle" color="success" />
                <KpiCard title="Partially Effective" value={summary.partial ?? 0} icon="warning" color="warning" />
                <KpiCard title="Ineffective" value={summary.ineffective ?? 0} icon="cancel" color="danger" />
            </div>

            <div className="flex flex-wrap gap-2 mb-4">
                {select('effectiveness', filters.effectiveness, Object.entries(EFFECTIVENESS).map(([value, band]) => (
                    <option key={value} value={value}>{band.label}</option>
                )), 'All effectiveness ratings')}
                {select('control_type', filters.control_type, TYPES.map((type) => (
                    <option key={type} value={type}>{titleCase(type)}</option>
                )), 'All control types')}
                {select('business_unit_id', filters.business_unit_id, businessUnits.map((unit) => (
                    <option key={unit.id} value={unit.id}>{unit.name}</option>
                )), 'All business units')}
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>Control</th>
                                <th>Type</th>
                                <th>Business Unit</th>
                                <th>Owner</th>
                                <th>Effectiveness</th>
                                <th>Linked Risks</th>
                                <th>Last Tested</th>
                                <th>Next Due</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(controls.data ?? []).length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-center py-12">
                                        <span className="material-symbols-outlined text-4xl text-gray-300 mb-2 block">verified_user</span>
                                        <p className="text-sm text-gray-500">No controls match these filters</p>
                                    </td>
                                </tr>
                            )}
                            {(controls.data ?? []).map((control) => {
                                const band = EFFECTIVENESS[control.effectiveness];

                                return (
                                    <tr key={control.id} className="hover:bg-blue-50/50">
                                        <td>
                                            <Link href={control.url} className="font-medium text-[#1A365D] hover:underline">
                                                {control.name}
                                            </Link>
                                            <div className="text-[10px] text-gray-400">{control.code}</div>
                                        </td>
                                        <td><span className="badge bg-gray-100 text-gray-700">{titleCase(control.type)}</span></td>
                                        <td className="text-xs">{control.businessUnit ?? '-'}</td>
                                        <td className="text-xs">{control.owner ?? '-'}</td>
                                        <td>
                                            {band ? (
                                                <span className={`badge ${band.class}`}>{band.label}</span>
                                            ) : (
                                                <span className="text-xs text-gray-400">Not rated</span>
                                            )}
                                        </td>
                                        <td className="text-xs">{control.linkedRisks}</td>
                                        <td className="text-xs text-gray-500">{control.lastTested ?? '-'}</td>
                                        <td className="text-xs text-gray-500">{control.nextDue ?? '-'}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <Pagination links={controls.links} meta={controls.meta} />
            </div>
        </AuthenticatedLayout>
    );
}
