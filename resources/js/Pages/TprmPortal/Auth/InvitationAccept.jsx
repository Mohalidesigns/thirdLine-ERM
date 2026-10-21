import { useForm } from '@inertiajs/react';
import PortalGuestLayout, { Field, PrimaryButton } from '@/Layouts/PortalGuestLayout';

/**
 * Accepting a vendor-portal invitation — FR-PRT-01. The token stays in the
 * URL the form posts to and is never written into the page, where a
 * screenshot or a referrer could carry it away.
 *
 * After this the account exists but can do nothing until a second factor is
 * set up — the server sends the vendor straight on to that step.
 */
export default function InvitationAccept({ token, email, vendor, client, expires_at: expiresAt }) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm-portal.invitation.accept', token), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <PortalGuestLayout title="Accept invitation" clientName={client}>
            <h1 className="text-lg font-semibold text-gray-900">Set up your account</h1>
            <p className="mt-1 text-sm text-gray-600">
                {client ?? 'Your client'} has invited you to respond on behalf of
                {' '}<span className="font-medium text-gray-900">{vendor ?? 'your organisation'}</span>.
                This invitation expires {expiresAt}.
            </p>

            {form.errors.email && (
                <div role="alert" className="mt-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900">
                    {form.errors.email}
                </div>
            )}

            <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                <div>
                    <p className="block text-sm font-medium text-gray-700">Email address</p>
                    <p className="mt-1 text-sm text-gray-900">{email}</p>
                </div>

                <Field
                    id="name"
                    label="Your full name"
                    autoComplete="name"
                    autoFocus
                    required
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    error={form.errors.name}
                />

                <Field
                    id="password"
                    type="password"
                    label="Choose a password"
                    autoComplete="new-password"
                    required
                    hint="At least 12 characters, with letters, numbers and symbols. Passwords found in public breaches are refused."
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    error={form.errors.password}
                />

                <Field
                    id="password_confirmation"
                    type="password"
                    label="Confirm password"
                    autoComplete="new-password"
                    required
                    value={form.data.password_confirmation}
                    onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    error={form.errors.password_confirmation}
                />

                <PrimaryButton disabled={form.processing}>
                    {form.processing ? 'Creating account…' : 'Create account'}
                </PrimaryButton>
            </form>

            <p className="mt-5 text-xs text-gray-500">
                Next you will set up a one-time code for signing in.
            </p>
        </PortalGuestLayout>
    );
}
