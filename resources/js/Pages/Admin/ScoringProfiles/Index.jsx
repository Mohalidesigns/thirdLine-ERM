import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import Modal from '@thirdline/ui/Components/Modal';

/**
 * Scoring profiles (migration Phase 6.4).
 *
 * The list only. Redefining what Critical means re-rates the whole register, so
 * it sits behind its own permission (`admin.scoring`) and its own page rather
 * than a dialog — see Edit.jsx.
 */
export default function Index({ profiles, options }) {
    const { flash } = usePage().props;
    const [deleting, setDeleting] = useState(null);

    const confirmDelete = () =>
        router.delete(route('admin.scoring-profiles.destroy', deleting.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });

    return (
        <AuthenticatedLayout title="Scoring Profiles">
            <Head title="Scoring Profiles" />

            <PageHeader
                title="Scoring profiles"
                subtitle="What a score means: the matrix, the scales, the bands and the residual formula"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Scoring profiles' },
                ]}
                actions={
                    <Link href={route('admin.scoring-profiles.create')}>
                        <PrimaryButton>New profile</PrimaryButton>
                    </Link>
                }
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="space-y-4">
                {profiles.map((profile) => (
                    <div key={profile.id} className="bg-white rounded-xl border border-gray-200 p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h3 className="text-sm font-semibold text-gray-900">{profile.name}</h3>
                                    {Boolean(profile.is_default) && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700">
                                            DEFAULT
                                        </span>
                                    )}
                                    {Boolean(profile.is_system) && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                            SYSTEM
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-gray-500 font-mono">{profile.code}</p>
                                {profile.description && (
                                    <p className="text-xs text-gray-500 mt-1 max-w-2xl">{profile.description}</p>
                                )}
                                <p className="text-xs text-gray-500 mt-2">
                                    {profile.matrix_rows}×{profile.matrix_cols} · top score {profile.max_score} ·{' '}
                                    {profile.impact_aggregation.replace(/_/g, ' ')} · {profile.impact_dimensions.length}{' '}
                                    dimension(s)
                                </p>
                            </div>

                            <div className="flex gap-3 shrink-0">
                                <Link
                                    href={route('admin.scoring-profiles.edit', profile.id)}
                                    className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                >
                                    {profile.is_system ? 'Fork and edit' : 'Edit'}
                                </Link>
                                {profile.can_delete && (
                                    <button
                                        type="button"
                                        onClick={() => setDeleting(profile)}
                                        className="text-xs text-red-600 font-medium hover:opacity-80"
                                    >
                                        Delete
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            {profile.rating_bands.map((band) => (
                                <span
                                    key={band.code}
                                    className="px-2 py-1 rounded-full text-xs font-medium text-white"
                                    style={{ backgroundColor: band.color || '#64748b' }}
                                >
                                    {band.label} {band.min}–{band.max}
                                </span>
                            ))}
                        </div>

                        {profile.residual_formula && profile.residual_formula !== options.defaultFormula && (
                            <p className="mt-3 text-xs text-gray-500">
                                Residual: <span className="font-mono">{profile.residual_formula}</span>
                            </p>
                        )}
                    </div>
                ))}

                {profiles.length === 0 && (
                    <div className="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500">
                        No scoring profiles yet.
                    </div>
                )}
            </div>

            <Modal show={deleting !== null} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900">Delete “{deleting?.name}”?</h2>
                    <p className="text-sm text-gray-600">
                        Risks that resolved to this profile will fall back to whichever profile applies next — usually
                        the seeded 5×5 — and be re-rated against its bands.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setDeleting(null)}>
                            Cancel
                        </SecondaryButton>
                        <button
                            type="button"
                            onClick={confirmDelete}
                            className="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
