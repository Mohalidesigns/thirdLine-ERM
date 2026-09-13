import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import Pagination from '@thirdline/ui/Components/Pagination';
import DriverFields from './DriverFields';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const STATUS_TONES = {
    completed: 'bg-green-100 text-green-800',
    running: 'bg-blue-100 text-blue-800',
    failed: 'bg-red-100 text-red-800',
};

/**
 * One connector and its run history (migration Phase 6.7).
 *
 * THE RUN HISTORY IS THE PART THAT EARNS ITS KEEP. "Read 412, wrote 0" is the
 * message an operator needs, and a green tick over a sync that imported nothing
 * is how a KRI quietly stops being measured — which nobody notices until the
 * breach that was never flagged. So the counts are columns, not a detail view.
 *
 * Credentials are rendered blank because the server never sends them, and the
 * update only overwrites what was actually typed — saving this page cannot wipe
 * what it was never shown.
 */
export default function Show({ connector, driver, schedules, runs, canManage, canRun }) {
    const { flash } = usePage().props;

    const form = useForm({
        name: connector.name ?? '',
        description: connector.description ?? '',
        config: connector.config ?? {},
        credentials: {},
        schedule: connector.schedule ?? '',
        is_active: Boolean(connector.is_active),
    });

    const save = (event) => {
        event.preventDefault();
        form.put(route('admin.connectors.update', connector.id), { preserveScroll: true });
    };

    const post = (routeName, data = {}, confirmation = null) => {
        if (confirmation && !window.confirm(confirmation)) return;

        router.post(route(routeName, connector.id), data, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title={connector.name}>
            <Head title={connector.name} />

            <PageHeader
                title={connector.name}
                subtitle={driver?.label ?? connector.type}
                breadcrumbs={[
                    { label: 'Connectors', href: route('admin.connectors.index') },
                    { label: connector.name },
                ]}
                actions={
                    <>
                        {canManage && (
                            <SecondaryButton type="button" onClick={() => post('admin.connectors.test')}>
                                Test connection
                            </SecondaryButton>
                        )}
                        {canRun && (
                            <>
                                <SecondaryButton
                                    type="button"
                                    onClick={() => post('admin.connectors.run', { dry_run: true })}
                                >
                                    Dry run
                                </SecondaryButton>
                                <PrimaryButton
                                    type="button"
                                    onClick={() =>
                                        post(
                                            'admin.connectors.run',
                                            {},
                                            'Run for real? This writes what it reads into this organisation.',
                                        )
                                    }
                                >
                                    Run now
                                </PrimaryButton>
                            </>
                        )}
                    </>
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

            {connector.disabled_reason && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {connector.disabled_reason} — re-enabling below clears the failure counter.
                </div>
            )}

            <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
                <form
                    onSubmit={save}
                    className="xl:col-span-1 bg-white rounded-xl border border-gray-200 p-5 space-y-4 self-start"
                >
                    <h2 className="text-sm font-semibold text-[#1A365D]">Settings</h2>

                    <div>
                        <InputLabel htmlFor="name" value="Name" />
                        <TextInput
                            id="name"
                            className="mt-1 block w-full"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            disabled={!canManage}
                            required
                        />
                        <InputError message={form.errors.name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={form.data.description ?? ''}
                            onChange={(e) => form.setData('description', e.target.value)}
                            disabled={!canManage}
                        />
                    </div>

                    <DriverFields
                        title="Driver settings"
                        fields={driver?.config ?? {}}
                        values={form.data.config}
                        errors={form.errors}
                        prefix="config"
                        onChange={(key, value) => form.setData('config', { ...form.data.config, [key]: value })}
                    />

                    <DriverFields
                        title="Credentials"
                        hint={
                            connector.has_credentials
                                ? 'Stored. Leave blank to keep what is there.'
                                : 'Nothing stored yet.'
                        }
                        fields={driver?.credentials ?? {}}
                        values={form.data.credentials}
                        errors={form.errors}
                        prefix="credentials"
                        onChange={(key, value) =>
                            form.setData('credentials', { ...form.data.credentials, [key]: value })
                        }
                    />

                    <div>
                        <InputLabel htmlFor="schedule" value="Schedule" />
                        <select
                            id="schedule"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                            value={form.data.schedule ?? ''}
                            onChange={(e) => form.setData('schedule', e.target.value)}
                            disabled={!canManage}
                        >
                            <option value="">Manual only</option>
                            {Object.entries(schedules).map(([value, label]) => (
                                <option key={value} value={value}>
                                    {typeof label === 'string' ? label : value}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.schedule} className="mt-1" />
                    </div>

                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.checked)}
                            disabled={!canManage}
                        />
                        <span className="text-sm text-gray-700">Active</span>
                    </label>

                    {canManage && (
                        <div className="flex items-center justify-between pt-2 border-t border-gray-100">
                            <button
                                type="button"
                                onClick={() => {
                                    if (!window.confirm('Remove this connector? Its run history is kept.')) return;

                                    router.delete(route('admin.connectors.destroy', connector.id));
                                }}
                                className="text-xs text-red-600 font-medium hover:opacity-80"
                            >
                                Remove
                            </button>
                            <PrimaryButton disabled={form.processing}>Save</PrimaryButton>
                        </div>
                    )}
                </form>

                <div className="xl:col-span-2 bg-white rounded-xl border border-gray-200 overflow-x-auto self-start">
                    <div className="px-5 pt-5">
                        <h2 className="text-sm font-semibold text-[#1A365D]">Run history</h2>
                        <p className="text-xs text-gray-500 mt-1">
                            A green tick over a sync that imported nothing is how a measure quietly stops being taken,
                            so the counts are here rather than behind a click.
                        </p>
                    </div>

                    <table className="data-table w-full mt-3">
                        <thead>
                            <tr>
                                <th>Started</th>
                                <th>Status</th>
                                <th className="text-right">Read</th>
                                <th className="text-right">Written</th>
                                <th className="text-right">Skipped</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-sm text-gray-500 text-center py-8">
                                        It has never run.
                                    </td>
                                </tr>
                            )}
                            {runs.data.map((run) => (
                                <tr key={run.id}>
                                    <td className="text-xs text-gray-600">
                                        {dateTime(run.started_at)}
                                        {run.dry_run && (
                                            <span className="ml-1 px-1 py-0.5 rounded bg-gray-100 text-[10px] font-semibold text-gray-600">
                                                DRY
                                            </span>
                                        )}
                                    </td>
                                    <td>
                                        <span
                                            className={`inline-flex px-2 py-0.5 rounded-full text-xs font-medium ${
                                                STATUS_TONES[run.status] ?? 'bg-gray-100 text-gray-700'
                                            }`}
                                        >
                                            {run.status}
                                        </span>
                                        {run.errors?.length > 0 && (
                                            <p className="text-xs text-red-600 mt-0.5">{run.errors.length} error(s)</p>
                                        )}
                                    </td>
                                    <td className="text-sm text-gray-600 text-right">{run.records_read ?? 0}</td>
                                    <td
                                        className={`text-sm text-right ${
                                            (run.records_read ?? 0) > 0 && (run.records_written ?? 0) === 0
                                                ? 'text-amber-700 font-semibold'
                                                : 'text-gray-600'
                                        }`}
                                    >
                                        {run.records_written ?? 0}
                                    </td>
                                    <td className="text-sm text-gray-600 text-right">{run.records_skipped ?? 0}</td>
                                    <td className="text-xs text-gray-500">{run.triggered_by ?? run.trigger}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <Pagination links={runs.links} meta={runs.meta} />
                </div>
            </div>

            <div className="mt-4">
                <Link href={route('admin.connectors.index')} className="text-sm text-gray-500 hover:text-gray-700">
                    Back to connectors
                </Link>
            </div>
        </AuthenticatedLayout>
    );
}
