import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import LibraryForm, { cleanLibraryPayload } from '@/Components/RiskLibrary/LibraryForm';

/**
 * Edit Master Risk Library definition — publishes v{current_version + 1} (ADR 0028 Phase 1).
 */
export default function Edit({ item, options = {} }) {
    const form = useForm({
        title: item?.title ?? '',
        description: item?.description ?? '',
        category_id: item?.category_id ? String(item.category_id) : '',
        risk_source: item?.risk_source ?? '',
        basel_l1_event_type: item?.basel_l1_event_type ?? '',
        basel_l2_event_type: item?.basel_l2_event_type ?? '',
        typical_causes: (item?.typical_causes ?? []).map((c) => ({
            cause: c.cause ?? '',
            cause_category_id: c.cause_category_id ? String(c.cause_category_id) : '',
        })),
        typical_consequences: (item?.typical_consequences ?? []).map((c) => ({
            consequence: c.consequence ?? '',
            impact_domain: c.impact_domain ?? '',
        })),
        suggested_controls: (item?.suggested_controls ?? []).map((c) => ({
            title: c.title ?? '',
            control_id: c.control_id ? String(c.control_id) : '',
            control_type: c.control_type ?? '',
        })),
        suggested_kris: (item?.suggested_kris ?? []).map((k) => ({
            name: k.name ?? '',
            kri_id: k.kri_id ? String(k.kri_id) : '',
            metric_unit: k.metric_unit ?? '',
        })),
        change_summary: '',
    });

    const nextVersion = Number(item.current_version) + 1;

    const handleSubmit = (e) => {
        e.preventDefault();
        form.transform((data) =>
            cleanLibraryPayload(data, { includeCode: false, includeChangeSummary: true }),
        );
        form.put(route('risk.library.update', item.id));
    };

    return (
        <AuthenticatedLayout title={`Edit ${item.code}`}>
            <Head title={`Edit ${item.code} — Risk Library`} />

            <PageHeader
                title={`Edit ${item.code} — ${item.title}`}
                subtitle={`Publishing changes will create version v${nextVersion}.`}
                breadcrumbs={[
                    { label: 'Risk Library', href: route('risk.library.index') },
                    { label: item.code, href: route('risk.library.show', item.id) },
                    { label: `Edit (v${nextVersion})` },
                ]}
            />

            <LibraryForm
                mode="edit"
                item={item}
                options={options}
                form={form}
                onSubmit={handleSubmit}
                cancelHref={route('risk.library.show', item.id)}
            />
        </AuthenticatedLayout>
    );
}
