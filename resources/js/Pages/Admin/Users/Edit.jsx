import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import UserForm from './UserForm';

export default function Edit({ subject, businessUnits, roles, isSelf }) {
    return (
        <AuthenticatedLayout title={`Edit ${subject.name}`}>
            <Head title={`Edit ${subject.name}`} />

            <PageHeader title={subject.name} subtitle={subject.email} />

            <UserForm subject={subject} businessUnits={businessUnits} roles={roles} isSelf={isSelf} />
        </AuthenticatedLayout>
    );
}
