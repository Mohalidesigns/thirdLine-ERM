import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import ScenarioForm from './ScenarioForm';

export default function Edit({ scenario, initial, risks, categories, distributions }) {
    return (
        <AuthenticatedLayout title={`Edit ${scenario.scenario_reference}`}>
            <Head title={`Edit ${scenario.scenario_reference}`} />

            <PageHeader
                title={scenario.name}
                subtitle={`${scenario.scenario_reference} · editing the calibration a simulation will draw on`}
            />

            <ScenarioForm
                scenario={scenario}
                initial={initial}
                risks={risks}
                categories={categories}
                distributions={distributions}
            />
        </AuthenticatedLayout>
    );
}
