import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DynamicForm, { formDataFor, initialValues } from '@/Components/DynamicForm';
import PageHeader from '@/Components/PageHeader';

/**
 * Migration Phase 4.6: risk/emerging/edit.blade.php (+ _form.blade.php).
 *
 * Schema-driven, for the reasons on Create.jsx. "Mark reviewed today" stays a
 * first-class action beside the form rather than a date field inside it — a
 * horizon whose entries are never revisited quietly rots, and confirming an
 * entry is still current is a different act from revising it.
 */
export default function Edit({ entry, schema = null, canDelete = false }) {
    const [removing, setRemoving] = useState(false);

    const { data, setData, put, processing, errors, transform } = useForm({
        ...initialValues(schema),
    });

    transform((form) => formDataFor(schema, form));

    const submit = (e) => {
        e.preventDefault();
        put(route('risk.emerging.update', entry.id));
    };

    const markReviewed = () => router.post(entry.reviewUrl, {}, { preserveScroll: true });

    const remove = () => {
        router.delete(route('risk.emerging.destroy', entry.id));
        setRemoving(false);
    };

    const subtitle = [
        entry.createdAt && `Added ${entry.createdAt}`,
        entry.createdBy && `by ${entry.createdBy}`,
        `Radar position ${entry.radarScore}/25`,
        entry.lastReviewedAt ? `Last reviewed ${entry.lastReviewedAt}` : 'Never reviewed',
    ].filter(Boolean).join(' · ');

    return (
        <AuthenticatedLayout title="Edit Emerging Risk">
            <Head title={`Edit ${entry.reference}`} />

            <PageHeader
                title={entry.reference}
                subtitle={subtitle}
                breadcrumbs={[
                    { label: 'Emerging Risk Register', href: route('risk.emerging.index') },
                    { label: entry.reference },
                ]}
                actions={
                    <>
                        <button type="button" onClick={markReviewed} className="btn-secondary inline-flex items-center gap-2 text-sm">
                            <span className="material-symbols-outlined text-base">event_available</span>
                            Mark reviewed today
                        </button>
                        {canDelete && (
                            <button
                                type="button"
                                onClick={() => setRemoving(true)}
                                className="px-4 py-2 border border-red-200 text-red-600 rounded-lg text-sm font-medium hover:bg-red-50"
                            >
                                Remove
                            </button>
                        )}
                    </>
                }
            />

            <form onSubmit={submit} className="max-w-5xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <div className="flex items-center gap-2 mb-6">
                        <span className="material-symbols-outlined text-[#D4AF37]">radar</span>
                        <h2 className="text-base font-bold text-[#1A365D]">{entry.title}</h2>
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
                        <span className="material-symbols-outlined text-lg">save</span> Save changes
                    </button>
                </div>
            </form>

            <ConfirmDialog
                show={removing}
                title="Remove this entry from the horizon?"
                message={`${entry.reference} — ${entry.title} — will be removed from the emerging risk register.`}
                confirmLabel="Remove"
                onConfirm={remove}
                onCancel={() => setRemoving(false)}
            />
        </AuthenticatedLayout>
    );
}
