import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DynamicForm, { formDataFor, initialValues } from '@/Components/DynamicForm';
import PageHeader from '@/Components/PageHeader';

/**
 * Migration Phase 4.6: risk/emerging/create.blade.php (+ _form.blade.php).
 *
 * The whole form comes from the EmergingRisk object type, as the Blade partial's
 * `<x-dynamic-form type="EmergingRisk">` did. Writing the fields out here would
 * duplicate FormFieldRegistry and silently drop every builder-added field —
 * 4.4's trap, and DynamicRendererTest asserts against it.
 *
 * A field a tenant adds through the builder now actually saves, which it did
 * not before this phase: EmergingRisk was missing from the graph's model type
 * map, so PersistsConfiguredAttributes could not resolve a type and discarded
 * the value without a word. See docs/migration/phase-4-notes/emerging.md.
 */
export default function Create({ schema = null }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        ...initialValues(schema),
    });

    transform((form) => formDataFor(schema, form));

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.emerging.store'));
    };

    return (
        <AuthenticatedLayout title="Add Emerging Risk">
            <Head title="Add Emerging Risk" />

            <PageHeader
                title="Add an emerging risk"
                subtitle="A reference is assigned automatically once you save"
                breadcrumbs={[
                    { label: 'Emerging Risk Register', href: route('risk.emerging.index') },
                    { label: 'Add' },
                ]}
            />

            <form onSubmit={submit} className="max-w-5xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <div className="flex items-center gap-2 mb-6">
                        <span className="material-symbols-outlined text-[#D4AF37]">radar</span>
                        <h2 className="text-base font-bold text-[#1A365D]">Horizon Entry</h2>
                    </div>

                    <DynamicForm
                        schema={schema}
                        values={data}
                        errors={errors}
                        onChange={(code, value) => setData(code, value)}
                    />
                </div>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.emerging.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Add to register
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
