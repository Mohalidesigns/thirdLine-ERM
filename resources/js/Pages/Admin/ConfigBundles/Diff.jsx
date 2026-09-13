import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';

const CATEGORIES = [
    ['conflicting', 'Conflicting', 'border-red-200 bg-red-50', 'text-red-900'],
    ['added', 'Added', 'border-green-200 bg-green-50', 'text-green-900'],
    ['changed', 'Changed', 'border-blue-200 bg-blue-50', 'text-blue-900'],
    ['removed', 'Removed', 'border-amber-200 bg-amber-50', 'text-amber-900'],
];

const show = (value) => {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'object') return JSON.stringify(value);

    return String(value);
};

function Total({ label, count, tone }) {
    return (
        <div className={`rounded-lg border px-4 py-3 ${tone}`}>
            <p className="text-2xl font-bold">{count}</p>
            <p className="text-xs uppercase tracking-wide">{label}</p>
        </div>
    );
}

/**
 * The dry run's result (migration Phase 6.6).
 *
 * A page rather than a flash. The Blade screen put the whole diff through the
 * session and read it back on the next request, which is a configuration-sized
 * document in the session store for the sake of one redirect.
 *
 * CONFLICTS ARE LISTED FIRST and shown field by field. A conflict is a row that
 * changed on BOTH sides since the last apply — the bundle's author edited it,
 * and so did somebody here — so applying without reading it is how one of those
 * two edits disappears silently.
 */
export default function Diff({ diff, bundle, uploaded }) {
    const { flash } = usePage().props;

    const applyForm = useForm({ force: false, prune: false, confirm: false });

    const sections = Object.entries(diff.sections ?? {});
    const totals = diff.totals ?? {};

    const apply = (event) => {
        event.preventDefault();
        applyForm.post(route('admin.configuration.apply', bundle.id));
    };

    return (
        <AuthenticatedLayout title="Configuration diff">
            <Head title="Configuration diff" />

            <PageHeader
                title="What this bundle would change"
                subtitle={
                    bundle
                        ? `${bundle.name} v${bundle.version} against this environment`
                        : 'An uploaded file against this environment'
                }
                breadcrumbs={[
                    { label: 'Configuration bundles', href: route('admin.configuration') },
                    { label: 'Dry run' },
                ]}
                actions={
                    <Link href={route('admin.configuration')}>
                        <SecondaryButton type="button">Back</SecondaryButton>
                    </Link>
                }
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}

            {diff.is_empty ? (
                <div className="bg-white rounded-xl border border-gray-200 p-8 text-center">
                    <p className="text-sm font-semibold text-gray-900">Nothing would change.</p>
                    <p className="text-sm text-gray-500 mt-1">
                        This configuration is already exactly what the bundle describes.
                    </p>
                </div>
            ) : (
                <>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                        <Total
                            label="Conflicting"
                            count={totals.conflicting ?? 0}
                            tone="border-red-200 bg-red-50 text-red-900"
                        />
                        <Total
                            label="Added"
                            count={totals.added ?? 0}
                            tone="border-green-200 bg-green-50 text-green-900"
                        />
                        <Total
                            label="Changed"
                            count={totals.changed ?? 0}
                            tone="border-blue-200 bg-blue-50 text-blue-900"
                        />
                        <Total
                            label="Removed"
                            count={totals.removed ?? 0}
                            tone="border-amber-200 bg-amber-50 text-amber-900"
                        />
                    </div>

                    {(totals.conflicting ?? 0) > 0 && (
                        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <span className="font-semibold">Read the conflicts before applying.</span> A conflict is a
                            row that changed on both sides since the last apply — the bundle's author edited it, and so
                            did somebody here. Applying through one discards the local edit.
                        </div>
                    )}

                    <div className="space-y-4">
                        {sections.map(([section, result]) => (
                            <div key={section} className="bg-white rounded-xl border border-gray-200 p-5">
                                <h2 className="text-sm font-semibold text-[#1A365D] mb-3">
                                    {section.replace(/_/g, ' ')}
                                </h2>

                                {CATEGORIES.map(([category, label, border, text]) => {
                                    const rows = result[category] ?? [];

                                    if (rows.length === 0) return null;

                                    return (
                                        <div key={category} className={`rounded-lg border ${border} p-3 mb-2`}>
                                            <p className={`text-xs font-semibold uppercase tracking-wide ${text} mb-2`}>
                                                {label} · {rows.length}
                                            </p>

                                            <div className="space-y-2">
                                                {rows.map((row) => (
                                                    <div key={row.key}>
                                                        <p className="text-xs font-mono text-gray-800">{row.key}</p>

                                                        {row.fields && (
                                                            <div className="overflow-x-auto mt-1">
                                                                <table className="text-xs w-full">
                                                                    <tbody>
                                                                        {Object.entries(row.fields).map(
                                                                            ([field, change]) => (
                                                                                <tr key={field}>
                                                                                    <td className="pr-3 py-0.5 text-gray-500 font-mono align-top">
                                                                                        {field}
                                                                                    </td>
                                                                                    <td className="pr-2 py-0.5 text-gray-500 line-through align-top break-all">
                                                                                        {show(change.from)}
                                                                                    </td>
                                                                                    <td className="py-0.5 text-gray-900 align-top break-all">
                                                                                        {show(change.to)}
                                                                                    </td>
                                                                                </tr>
                                                                            ),
                                                                        )}
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </>
            )}

            {bundle ? (
                <form onSubmit={apply} className="bg-white rounded-xl border border-gray-200 p-5 mt-4 space-y-3">
                    <h2 className="text-sm font-semibold text-[#1A365D]">Apply it</h2>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.force}
                            onChange={(e) => applyForm.setData('force', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Proceed through the {totals.conflicting ?? 0} conflict(s)
                        </span>
                    </label>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.prune}
                            onChange={(e) => applyForm.setData('prune', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Remove the {totals.removed ?? 0} row(s) the bundle does not carry
                        </span>
                    </label>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={applyForm.data.confirm}
                            onChange={(e) => applyForm.setData('confirm', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">I have read what this will change.</span>
                    </label>
                    <InputError message={applyForm.errors.confirm} />

                    <div className="flex justify-end">
                        <PrimaryButton disabled={applyForm.processing || !applyForm.data.confirm}>
                            Apply this bundle
                        </PrimaryButton>
                    </div>
                </form>
            ) : (
                uploaded && (
                    <p className="mt-4 text-sm text-gray-500">
                        This diff came from an uploaded file. Applying works from a stored bundle — export this
                        environment first if you want a rollback point, then import the file through{' '}
                        <span className="font-mono">config:import</span>.
                    </p>
                )
            )}
        </AuthenticatedLayout>
    );
}
