import { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';
import DriverFields from './DriverFields';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : 'never';

/**
 * Connectors (migration Phase 6.7).
 *
 * The form is built from each driver's own `describe()`, so a new connector
 * type gets a UI without anybody writing one. That is the reason `config`,
 * `credentials` and `field_map` stay free-form arrays server-side: their shape
 * is the driver's, not this screen's.
 */
export default function Index({ connectors, drivers, schedules, canManage }) {
    const { flash, errors } = usePage().props;
    const [creating, setCreating] = useState(false);

    const form = useForm({
        type: Object.keys(drivers)[0] ?? '',
        name: '',
        description: '',
        config: {},
        credentials: {},
        schedule: '',
    });

    const driver = drivers[form.data.type] ?? null;

    const submit = (event) => {
        event.preventDefault();
        form.post(route('admin.connectors.store'), { onSuccess: () => setCreating(false) });
    };

    return (
        <AuthenticatedLayout title="Connectors">
            <Head title="Connectors" />

            <PageHeader
                title="Connectors"
                subtitle="Where this platform reads from, and what each run actually brought back"
                breadcrumbs={[{ label: 'Administration' }, { label: 'Connectors' }]}
                actions={canManage && <PrimaryButton onClick={() => setCreating(true)}>New connector</PrimaryButton>}
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
            {Object.entries(errors ?? {}).length > 0 && !creating && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 space-y-1">
                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} className="text-sm text-red-800">
                            {message}
                        </p>
                    ))}
                </div>
            )}

            <div className="space-y-4">
                {connectors.length === 0 && (
                    <div className="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500">
                        Nothing configured yet.
                    </div>
                )}

                {connectors.map((connector) => (
                    <Link
                        key={connector.id}
                        href={route('admin.connectors.show', connector.id)}
                        className="block bg-white rounded-xl border border-gray-200 p-5 hover:border-[#1A365D] transition"
                    >
                        <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h3 className="text-sm font-semibold text-gray-900">{connector.name}</h3>
                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                        {drivers[connector.type]?.label ?? connector.type}
                                    </span>
                                    {!connector.is_active && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-800">
                                            INACTIVE
                                        </span>
                                    )}
                                </div>
                                {connector.description && (
                                    <p className="text-xs text-gray-500 mt-1">{connector.description}</p>
                                )}
                                {connector.disabled_reason && (
                                    <p className="text-xs text-red-700 mt-1">{connector.disabled_reason}</p>
                                )}
                            </div>

                            <div className="text-right shrink-0">
                                <p className="text-xs text-gray-500">{connector.runs_count} run(s)</p>
                                <p className="text-xs text-gray-400">last run {dateTime(connector.last_run_at)}</p>
                                {connector.schedule && (
                                    <p className="text-xs text-gray-400">
                                        {schedules[connector.schedule] ?? connector.schedule}
                                    </p>
                                )}
                            </div>
                        </div>
                    </Link>
                ))}
            </div>

            <Modal show={creating} onClose={() => setCreating(false)} maxWidth="3xl">
                <form onSubmit={submit} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">New connector</h2>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel htmlFor="type" value="Type" />
                            <select
                                id="type"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.type}
                                onChange={(e) =>
                                    form.setData({ ...form.data, type: e.target.value, config: {}, credentials: {} })
                                }
                            >
                                {Object.entries(drivers).map(([type, meta]) => (
                                    <option key={type} value={type}>
                                        {meta.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.type} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                    </div>

                    {driver?.description && <p className="text-xs text-gray-500">{driver.description}</p>}

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    <DriverFields
                        title="Settings"
                        fields={driver?.config ?? {}}
                        values={form.data.config}
                        errors={form.errors}
                        prefix="config"
                        onChange={(key, value) => form.setData('config', { ...form.data.config, [key]: value })}
                    />

                    <DriverFields
                        title="Credentials"
                        hint="Never sent back to the browser once saved."
                        fields={driver?.credentials ?? {}}
                        values={form.data.credentials}
                        errors={form.errors}
                        prefix="credentials"
                        onChange={(key, value) =>
                            form.setData('credentials', { ...form.data.credentials, [key]: value })
                        }
                    />

                    <div className="sm:w-1/2">
                        <InputLabel htmlFor="schedule" value="Schedule" />
                        <select
                            id="schedule"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.schedule ?? ''}
                            onChange={(e) => form.setData('schedule', e.target.value)}
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

                    <div className="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={() => setCreating(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Create</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
