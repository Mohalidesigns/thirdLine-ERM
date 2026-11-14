import { useForm } from '@inertiajs/react';
import PortalGuestLayout, { Field, PrimaryButton } from '@/Layouts/PortalGuestLayout';

/**
 * Password step of the vendor sign-in — FR-PRT-01. The client is in the path;
 * a successful password parks the account as pending and the server sends the
 * vendor on to the code step. No session exists until that step passes.
 */
export default function Login({ client }) {
    const form = useForm({ email: '', password: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm-portal.login.attempt', client.uuid), {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <PortalGuestLayout title="Sign in" clientName={client.name}>
            <h1 className="text-lg font-semibold text-gray-900">Sign in</h1>
            <p className="mt-1 text-sm text-gray-600">
                Respond to {client.name}'s requests, share documents and track findings.
            </p>

            <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                <Field
                    id="email"
                    type="email"
                    label="Email address"
                    autoComplete="username"
                    autoFocus
                    required
                    value={form.data.email}
                    onChange={(event) => form.setData('email', event.target.value)}
                    error={form.errors.email}
                />

                <Field
                    id="password"
                    type="password"
                    label="Password"
                    autoComplete="current-password"
                    required
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={form.errors.password}
                />

                <PrimaryButton disabled={form.processing}>
                    {form.processing ? 'Signing in…' : 'Continue'}
                </PrimaryButton>
            </form>

            <p className="mt-5 text-xs text-gray-500">
                You will be asked for a one-time code next. Forgotten your password, or never set one? Ask your
                contact at {client.name} to send you a new invitation.
            </p>
        </PortalGuestLayout>
    );
}
