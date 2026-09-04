import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import TestForm from './TestForm';

/** Migration Phase 3.4: risk/controls/tests/edit.blade.php. */
export default function Edit({ test, controls = [], users = [], options = {} }) {
    const { data, setData, put, processing, errors } = useForm({
        control_id: test.control_id ?? '',
        title: test.title ?? '',
        description: test.description ?? '',
        test_type: test.test_type ?? '',
        tester_id: test.tester_id ?? '',
        reviewer_id: test.reviewer_id ?? '',
        scheduled_date: test.scheduled_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.control-tests.update', test.id));
    };

    return (
        <AuthenticatedLayout title={`Edit ${test.test_code}`}>
            <Head title={`Edit ${test.test_code}`} />

            <PageHeader
                title={`Edit ${test.test_code}`}
                subtitle={test.title}
                breadcrumbs={[
                    { label: 'Control Testing', href: route('risk.control-tests.dashboard') },
                    { label: test.test_code, href: route('risk.control-tests.show', test.id) },
                    { label: 'Edit' },
                ]}
            />

            <form onSubmit={submit} className="max-w-4xl">
                {/* control_id is locked: UpdateControlTestRequest does not accept
                    it, exactly as the Blade edit form did not offer it. */}
                <TestForm {...{ data, setData, errors, controls, users, options }} lockControl />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.control-tests.show', test.id)} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Save Changes
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
