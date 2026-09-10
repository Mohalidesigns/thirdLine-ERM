import { Head, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

function Stat({ value, label, icon, tone }) {
    return (
        <div className="kpi-card">
            <div className="flex items-center gap-3">
                <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${tone.bg}`}>
                    <span className={`material-symbols-outlined ${tone.text}`}>{icon}</span>
                </div>
                <div>
                    <p className="text-2xl font-bold text-gray-900">{value}</p>
                    <p className="text-xs text-gray-500">{label}</p>
                </div>
            </div>
        </div>
    );
}

/**
 * User administration (migration Phase 2) — see
 * App\Grids\Definitions\AdminUsersGrid. Activation stays on the user's own
 * page: the controller refuses to let anyone toggle their own account.
 */
export default function Index({ totalUsers, activeUsers, inactiveUsers, mfaEnabled, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const createUrl = tryRoute('admin.users.create');

    return (
        <AuthenticatedLayout title="User Management">
            <Head title="User Management" />

            <PageHeader
                title="User Management"
                subtitle="Manage system users, roles, and access permissions"
                actions={
                    permissions.includes('admin.users') && createUrl && (
                        <a href={createUrl} className="btn-primary text-sm inline-flex items-center gap-2">
                            <span className="material-symbols-outlined text-lg">person_add</span> Create User
                        </a>
                    )
                }
            />

            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <Stat value={totalUsers} label="Total Users" icon="group" tone={{ bg: 'bg-blue-100', text: 'text-blue-600' }} />
                <Stat value={activeUsers} label="Active Users" icon="check_circle" tone={{ bg: 'bg-green-100', text: 'text-green-600' }} />
                <Stat value={inactiveUsers} label="Inactive Users" icon="block" tone={{ bg: 'bg-gray-100', text: 'text-gray-500' }} />
                <Stat value={mfaEnabled} label="MFA Enabled" icon="verified_user" tone={{ bg: 'bg-purple-100', text: 'text-purple-600' }} />
            </div>

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
