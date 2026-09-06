import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import TestForm from './TestForm';

/** Migration Phase 3.4: risk/controls/tests/create.blade.php. */
export default function Create({ controls = [], users = [], options = {}, controlId = null }) {
    const { data, setData, post, processing, errors } = useForm({
        control_id: controlId ?? '',
        title: '',
        description: '',
        test_type: '',
        tester_id: '',
        reviewer_id: '',
        scheduled_date: new Date().toISOString().slice(0, 10),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.control-tests.store'));
    };

    return (
        <AuthenticatedLayout title="Schedule a Control Test">
            <Head title="Schedule a Control Test" />

            <PageHeader
                title="Schedule a Control Test"
                subtitle="Who tests what, when, and who reviews the result"
                breadcrumbs={[
                    { label: 'Control Testing', href: route('risk.control-tests.dashboard') },
                    { label: 'Schedule a Test' },
                ]}
            />

            <form onSubmit={submit} className="max-w-4xl">
                <TestForm {...{ data, setData, errors, controls, users, options }} />

                <div className="flex items-center justify-between">
                    <Link href={route('risk.control-tests.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">event_available</span> Schedule Test
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
