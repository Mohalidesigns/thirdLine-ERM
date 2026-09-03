import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DashboardBuilder from '@/Components/DashboardBuilder';

export default function Edit(props) {
    const { dashboard, urls } = props;

    const breadcrumbs = (
        <nav className="flex items-center gap-1 text-xs text-gray-500">
            <Link href={urls.index} className="hover:text-gray-800">Dashboards</Link>
            <span className="text-gray-300">›</span>
            <span className="font-medium text-gray-800">{dashboard.name}</span>
        </nav>
    );

    return (
        <AuthenticatedLayout title="Dashboards" header={breadcrumbs}>
            <Head title={`Edit · ${dashboard.name}`} />
            <DashboardBuilder {...props} />
        </AuthenticatedLayout>
    );
}
