import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import Modal from '@thirdline/ui/Components/Modal';

const dateTime = (value) =>
    value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

/**
 * API tokens (migration Phase 6.7).
 *
 * THE PLAINTEXT IS SHOWN ONCE. Only its hash is stored, so a lost token is
 * reissued rather than recovered — and the reveal panel arrives as a prop on
 * this page only, never in the props every page receives.
 *
 * A user may always issue a token FOR THEMSELVES: a personal token cannot
 * exceed the permissions of the person behind it, so the worst anyone can do
 * with one is what they could already do by logging in. A MACHINE TOKEN is a
 * different act — it acts as nobody, so its scopes are the whole of its
 * authority — and needs api.tokens.manage, may not ask for `*`, and always
 * expires.
 */
export default function Index({ tokens, canManage, scopes, machineLifetime, revealedToken, revealedTokenFor }) {
    const { flash } = usePage().props;
    const [issuing, setIssuing] = useState(false);

    const form = useForm({
        name: '',
        description: '',
        scopes: [],
        expires_in_days: '',
        token_type: 'personal',
        rate_limit_per_minute: 120,
    });

    const isMachine = form.data.token_type === 'client_credentials';

    const submit = (event) => {
        event.preventDefault();
        form.post(route('admin.api-tokens.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setIssuing(false);
            },
        });
    };

    const toggleScope = (scope) =>
        form.setData(
            'scopes',
            form.data.scopes.includes(scope)
                ? form.data.scopes.filter((s) => s !== scope)
                : [...form.data.scopes, scope],
        );

    const revoke = (token) => {
        if (!window.confirm('Revoke this token? Any request presenting it is refused from now on.')) return;

        router.delete(route('admin.api-tokens.destroy', token.id), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="API Tokens">
            <Head title="API Tokens" />

            <PageHeader
                title="API tokens"
                subtitle="Credentials for this organisation's integrations"
                breadcrumbs={[{ label: 'Administration' }, { label: 'API tokens' }]}
                actions={<PrimaryButton onClick={() => setIssuing(true)}>Issue a token</PrimaryButton>}
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

            {revealedToken && (
                <div className="mb-4 rounded-xl border-2 border-amber-300 bg-amber-50 p-5">
                    <p className="text-sm font-semibold text-amber-900">Token for “{revealedTokenFor}”</p>
                    <p className="text-xs text-amber-800 mt-1">
                        Copy it now. Only its hash is stored, so it cannot be shown again — a lost token is reissued,
                        not recovered.
                    </p>
                    <code className="mt-3 block bg-white border border-amber-200 rounded-lg px-3 py-2 font-mono text-xs break-all">
                        {revealedToken}
                    </code>
                </div>
            )}

            {!canManage && (
                <p className="mb-4 text-sm text-gray-500">
                    You are seeing your own tokens. Listing everyone's shows which integrations exist and when they last
                    ran, so it needs the api.tokens.manage permission.
                </p>
            )}

            <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table className="data-table w-full">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Type</th>
                            <th>Scopes</th>
                            <th>Expires</th>
                            <th>Last used</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {tokens.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-sm text-gray-500 text-center py-8">
                                    No tokens issued.
                                </td>
                            </tr>
                        )}
                        {tokens.map((token) => (
                            <tr key={token.id} className={token.revoked_at ? 'opacity-50' : ''}>
                                <td>
                                    <p className="text-sm font-medium text-gray-900">{token.name}</p>
                                    {token.client_id && (
                                        <p className="text-xs text-gray-500 font-mono">{token.client_id}</p>
                                    )}
                                    {token.description && <p className="text-xs text-gray-500">{token.description}</p>}
                                    {token.revoked_at && (
                                        <p className="text-xs text-red-600">
                                            revoked {dateTime(token.revoked_at)}
                                            {token.revoker ? ` by ${token.revoker}` : ''}
                                        </p>
                                    )}
                                </td>
                                <td className="text-xs text-gray-600">
                                    {token.token_type === 'client_credentials' ? 'Machine' : 'Personal'}
                                </td>
                                <td className="text-xs text-gray-500 max-w-xs">
                                    <span className="line-clamp-2 break-words">{token.abilities.join(', ')}</span>
                                </td>
                                <td className="text-xs text-gray-600">{dateTime(token.expires_at)}</td>
                                <td className="text-xs text-gray-600">{dateTime(token.last_used_at)}</td>
                                <td className="text-right">
                                    {token.can_revoke && !token.revoked_at && (
                                        <button
                                            type="button"
                                            onClick={() => revoke(token)}
                                            className="text-xs text-red-600 font-medium hover:opacity-80"
                                        >
                                            Revoke
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Modal show={issuing} onClose={() => setIssuing(false)} maxWidth="3xl">
                <form onSubmit={submit} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">Issue a token</h2>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                            <InputLabel htmlFor="token_type" value="Type" />
                            <select
                                id="token_type"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.token_type}
                                onChange={(e) => form.setData('token_type', e.target.value)}
                            >
                                <option value="personal">Personal — acts as me</option>
                                {canManage && <option value="client_credentials">Machine — acts as nobody</option>}
                            </select>
                            <InputError message={form.errors.token_type} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="What it is for" />
                        <TextInput
                            id="description"
                            className="mt-1 block w-full"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    {isMachine && (
                        <p className="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-lg p-3">
                            A machine token acts as no user, so its scopes are its whole authority. It may not ask for
                            everything, and it expires — after {machineLifetime.default_days} days by default, and{' '}
                            {machineLifetime.max_days} at the very most.
                        </p>
                    )}

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel htmlFor="expires_in_days" value="Expires in (days)" />
                            <TextInput
                                id="expires_in_days"
                                type="number"
                                min="1"
                                max="3650"
                                className="mt-1 block w-full"
                                value={form.data.expires_in_days}
                                onChange={(e) => form.setData('expires_in_days', e.target.value)}
                                placeholder={isMachine ? String(machineLifetime.default_days) : 'Never'}
                            />
                            <InputError message={form.errors.expires_in_days} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="rate_limit_per_minute" value="Rate limit (per minute)" />
                            <TextInput
                                id="rate_limit_per_minute"
                                type="number"
                                min="1"
                                max="10000"
                                className="mt-1 block w-full"
                                value={form.data.rate_limit_per_minute}
                                onChange={(e) => form.setData('rate_limit_per_minute', e.target.value)}
                            />
                            <InputError message={form.errors.rate_limit_per_minute} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <p className="text-sm font-medium text-gray-700 mb-1">Scopes</p>
                        <p className="text-xs text-gray-500 mb-2">
                            A personal token is narrowed by what you can do anyway, so `*` on one means "whatever I can
                            do". A machine token has no owner to narrow it, so it needs a named list.
                        </p>

                        {!isMachine && (
                            <label className="flex items-center gap-2 p-2 rounded bg-blue-50 cursor-pointer mb-2">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                    checked={form.data.scopes.includes('*')}
                                    onChange={() => toggleScope('*')}
                                />
                                <span className="text-sm text-gray-700 font-mono">* — everything I can do</span>
                            </label>
                        )}

                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-1 max-h-56 overflow-y-auto">
                            {scopes.map((scope) => (
                                <label
                                    key={scope}
                                    className="flex items-center gap-2 p-1.5 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={form.data.scopes.includes(scope)}
                                        onChange={() => toggleScope(scope)}
                                    />
                                    <span className="text-xs font-mono text-gray-700">{scope}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.scopes} className="mt-1" />
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('scopes.'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
                    </div>

                    <div className="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={() => setIssuing(false)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Issue</PrimaryButton>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
