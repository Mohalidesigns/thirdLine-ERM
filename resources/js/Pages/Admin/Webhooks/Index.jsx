import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

const blank = { name: '', description: '', url: '', events: [], is_active: true };

/**
 * Outbound webhooks (migration Phase 6.7).
 *
 * THE SIGNING SECRET IS SHOWN ONCE. Only its encrypted form is kept, so the
 * list below never carries it and a lost secret is rotated rather than
 * recovered. The reveal panel is a one-shot flash from the server.
 *
 * The event list is the server's, derived from the API resource registry, so
 * the webhook vocabulary and the API resource names stay the same words for
 * the same things — and it is the same list the validator accepts.
 */
export default function Index({ subscriptions, events, revealedSecret, revealedSecretFor }) {
    const { flash, errors } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const form = useForm({ ...blank });
    const isEdit = editing !== null && editing.id !== undefined;

    const openCreate = () => {
        form.setData({ ...blank });
        form.clearErrors();
        setEditing({});
    };

    const openEdit = (webhook) => {
        form.setData({
            name: webhook.name ?? '',
            description: webhook.description ?? '',
            url: webhook.url ?? '',
            events: webhook.events ?? [],
            is_active: Boolean(webhook.is_active),
        });
        form.clearErrors();
        setEditing(webhook);
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isEdit) {
            form.put(route('admin.webhooks.update', editing.id), done);
        } else {
            form.post(route('admin.webhooks.store'), done);
        }
    };

    const toggleEvent = (name) =>
        form.setData(
            'events',
            form.data.events.includes(name) ? form.data.events.filter((e) => e !== name) : [...form.data.events, name],
        );

    const post = (routeName, id, confirmation) => {
        if (confirmation && !window.confirm(confirmation)) return;

        router.post(route(routeName, id), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Webhooks">
            <Head title="Webhooks" />

            <PageHeader
                title="Webhooks"
                subtitle="Where this organisation's events are sent, and whether they arrived"
                breadcrumbs={[{ label: 'Administration' }, { label: 'Webhooks' }]}
                actions={<PrimaryButton onClick={openCreate}>New webhook</PrimaryButton>}
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
            {Object.entries(errors ?? {}).length > 0 && !editing && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 space-y-1">
                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} className="text-sm text-red-800">
                            {message}
                        </p>
                    ))}
                </div>
            )}

            {revealedSecret && (
                <div className="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-5">
                    <p className="text-sm font-semibold text-amber-900">Signing secret for “{revealedSecretFor}”</p>
                    <p className="text-xs text-amber-800 mt-1">
                        Copy it now. Only its encrypted form is kept, so it cannot be shown again — a lost secret is
                        rotated, not recovered.
                    </p>
                    <code className="mt-3 block bg-white border border-amber-200 rounded-lg px-3 py-2 font-mono text-xs break-all">
                        {revealedSecret}
                    </code>
                </div>
            )}

            <div className="space-y-4">
                {subscriptions.length === 0 && (
                    <div className="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500">
                        Nothing is subscribed yet.
                    </div>
                )}

                {subscriptions.map((webhook) => (
                    <div key={webhook.id} className="bg-white rounded-xl border border-gray-200 p-5">
                        <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <h3 className="text-sm font-semibold text-gray-900">{webhook.name}</h3>
                                    {webhook.is_active ? (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-800">
                                            ACTIVE
                                        </span>
                                    ) : (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                            INACTIVE
                                        </span>
                                    )}
                                    {webhook.disabled_at && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-800">
                                            AUTO-DISABLED
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-gray-500 font-mono break-all mt-0.5">{webhook.url}</p>
                                {webhook.description && (
                                    <p className="text-xs text-gray-500 mt-1">{webhook.description}</p>
                                )}
                                {webhook.disabled_reason && (
                                    <p className="text-xs text-red-700 mt-1">{webhook.disabled_reason}</p>
                                )}

                                <div className="flex flex-wrap gap-1 mt-2">
                                    {webhook.events.map((name) => (
                                        <span
                                            key={name}
                                            className="px-1.5 py-0.5 rounded bg-gray-100 text-[11px] font-mono text-gray-700"
                                        >
                                            {name}
                                        </span>
                                    ))}
                                </div>
                            </div>

                            <div className="shrink-0 text-right">
                                <p className="text-xs text-gray-500">
                                    <span className="text-green-700 font-semibold">{webhook.delivered_count}</span>{' '}
                                    delivered ·{' '}
                                    <span className="text-red-700 font-semibold">{webhook.failed_count}</span> failed
                                </p>
                                <p className="text-xs text-gray-400 mt-0.5">
                                    last delivered {dateTime(webhook.last_delivered_at)}
                                </p>

                                <div className="flex flex-wrap justify-end gap-3 mt-3">
                                    <Link
                                        href={webhook.deliveries_url}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                    >
                                        Delivery log
                                    </Link>
                                    {webhook.can_manage && (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => openEdit(webhook)}
                                                className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => post('admin.webhooks.test', webhook.id)}
                                                className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                            >
                                                Send a test
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    post(
                                                        'admin.webhooks.rotate-secret',
                                                        webhook.id,
                                                        'Rotate the secret? Every receiver has to be updated — deliveries signed with the old one will fail verification.',
                                                    )
                                                }
                                                className="text-xs text-amber-700 font-medium hover:opacity-80"
                                            >
                                                Rotate secret
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setDeleting(webhook)}
                                                className="text-xs text-red-600 font-medium hover:opacity-80"
                                            >
                                                Remove
                                            </button>
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="3xl">
                <form onSubmit={submit} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">
                        {isEdit ? `Edit ${editing.name}` : 'New webhook'}
                    </h2>

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

                    <div>
                        <InputLabel htmlFor="url" value="Endpoint URL" />
                        <TextInput
                            id="url"
                            type="url"
                            className="mt-1 block w-full font-mono text-sm"
                            value={form.data.url}
                            onChange={(e) => form.setData('url', e.target.value)}
                            required
                        />
                        <p className="text-xs text-gray-500 mt-1">
                            Checked when you save, not when the first delivery fails.
                        </p>
                        <InputError message={form.errors.url} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={form.data.description ?? ''}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    <div>
                        <p className="text-sm font-medium text-gray-700 mb-2">Events</p>
                        <div className="space-y-3 max-h-64 overflow-y-auto">
                            {Object.entries(events).map(([resource, names]) => (
                                <div key={resource}>
                                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                                        {resource}
                                    </p>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1">
                                        {names.map((name) => (
                                            <label
                                                key={name}
                                                className="flex items-center gap-2 p-1.5 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                    checked={form.data.events.includes(name)}
                                                    onChange={() => toggleEvent(name)}
                                                />
                                                <span className="text-xs font-mono text-gray-700">{name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <InputError message={form.errors.events} className="mt-1" />
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('events.'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
                    </div>

                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={form.data.is_active}
                            onChange={(e) => form.setData('is_active', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">Active</span>
                    </label>

                    <div className="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Save</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={deleting !== null} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900">Remove “{deleting?.name}”?</h2>
                    <p className="text-sm text-gray-600">
                        Its delivery history is kept — the log is what answers "did you send it" after an incident.
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setDeleting(null)}>
                            Cancel
                        </SecondaryButton>
                        <button
                            type="button"
                            onClick={() =>
                                router.delete(route('admin.webhooks.destroy', deleting.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleting(null),
                                })
                            }
                            className="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
