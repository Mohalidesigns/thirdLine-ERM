import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import ScenarioForm from './ScenarioForm';

export default function Create({ initial, risks, categories, distributions }) {
    return (
        <AuthenticatedLayout title="New Scenario">
            <Head title="New Scenario" />

            <PageHeader
                title="New Risk Scenario"
                subtitle="One loss event with a frequency and a severity, for Monte Carlo simulation"
            />

            <ScenarioForm initial={initial} risks={risks} categories={categories} distributions={distributions} />
        </AuthenticatedLayout>
    );
}
