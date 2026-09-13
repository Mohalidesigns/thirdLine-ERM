import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Phase 0 shell for one BCMS sub-module.
 *
 * A BLANK SCREEN IS INDISTINGUISHABLE FROM A BROKEN ONE. Phase 0 registers the
 * URL and the permission for all twelve sub-modules so that four parallel
 * tracks build against settled routes; what a user sees until their phase lands
 * is a page that says which phase delivers it, what it will do, and which
 * clause it answers. That is honest, and it is also the demo script.
 */
export default function Section({ section, sections = [] }) {
    return (
        <AppLayout title={section.label}>
            <Head title={section.label} />

            <PageHeader title={section.label} subtitle={section.summary} />

            <div className="mb-6 rounded-lg border border-gray-200 bg-white p-6">
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Arrives in {section.phase}</p>
                <p className="mt-3 text-sm text-gray-700">{section.lands}</p>
                <p className="mt-4 text-xs text-gray-500">
                    Evidence for {section.clause}. The schema, the permissions and this URL are frozen; the screen behind
                    it is built by the phase named above.
                </p>
            </div>

            <nav aria-label="Business continuity sub-modules">
                <h2 className="mb-3 text-sm font-semibold text-gray-900">Elsewhere in the module</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {sections
                        .filter((other) => other.key !== section.key)
                        .map((other) => (
                            <Link
                                key={other.key}
                                href={tryRoute(`bcms.${other.key}.index`)}
                                className="rounded-lg border border-gray-200 bg-white p-3 text-sm transition hover:border-gray-400"
                            >
                                <span className="font-medium text-gray-900">{other.label}</span>
                                <span className="mt-1 block text-xs text-gray-500">{other.phase}</span>
                            </Link>
                        ))}
                </div>
            </nav>
        </AppLayout>
    );
}
