import { router, useForm } from '@inertiajs/react';
import QrCode from '@thirdline/ui/Components/QrCode';
import PortalGuestLayout, { Field, LinkButton, PrimaryButton } from '@/Layouts/PortalGuestLayout';

/**
 * Authenticator-app enrolment — FR-PRT-01. Only a vendor on the TOTP method
 * reaches this; the emailed code needs no setup. The code that confirms
 * enrolment also completes the sign-in, so there is no second prompt.
 *
 * The QR is drawn in the browser from the otpauth URI the server issued; the
 * secret never leaves for a third-party QR service.
 */
export default function MfaSetup({ secret, uri, email }) {
    const form = useForm({ code: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm-portal.mfa.enrol'), {
            onError: () => {
                form.reset('code');
                // Resetting disables the submit button; keep focus on the field and its error.
                document.getElementById('code')?.focus();
            },
        });
    };

    const grouped = (secret ?? '').replace(/\s+/g, '').match(/.{1,4}/g)?.join(' ') ?? '';

    return (
        <PortalGuestLayout title="Set up your authenticator">
            <h1 className="text-lg font-semibold text-gray-900">Set up your authenticator app</h1>
            <p className="mt-1 text-sm text-gray-600">
                Signing in to the portal needs a one-time code. Scan this code with an authenticator app
                (for example Microsoft Authenticator or Google Authenticator) for <span className="font-medium text-gray-900">{email}</span>.
            </p>

            <div className="mt-5 flex justify-center">
                <QrCode value={uri} size={180} label="Authenticator enrolment code" />
            </div>

            <div className="mt-4 rounded border border-gray-200 bg-gray-50 px-4 py-3">
                <p className="text-xs text-gray-600">Can't scan it? Enter this key in the app instead:</p>
                <p className="mt-1 break-all font-mono text-sm tracking-wider text-gray-900">{grouped}</p>
            </div>

            <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                <Field
                    id="code"
                    label="Code from the app"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    required
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                    error={form.errors.code}
                />

                <PrimaryButton disabled={form.processing || form.data.code.length !== 6}>
                    {form.processing ? 'Checking…' : 'Confirm and sign in'}
                </PrimaryButton>
            </form>

            <div className="mt-5 text-right">
                <LinkButton onClick={() => router.post(route('tprm-portal.mfa.abandon'))}>Cancel sign-in</LinkButton>
            </div>
        </PortalGuestLayout>
    );
}
