import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import UserForm from './UserForm';

export default function Create({ businessUnits, roles }) {
    return (
        <AuthenticatedLayout title="New User">
            <Head title="New User" />

            <PageHeader
                title="New User"
                subtitle="The account is created with a temporary password, shown once on the next screen"
            />

            <UserForm businessUnits={businessUnits} roles={roles} />
        </AuthenticatedLayout>
    );
}
