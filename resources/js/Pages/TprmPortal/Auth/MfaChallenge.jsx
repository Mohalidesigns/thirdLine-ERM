import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import PortalGuestLayout, { Field, LinkButton, PrimaryButton } from '@/Layouts/PortalGuestLayout';

/**
 * The second step of the vendor sign-in — FR-PRT-01. The session is minted
 * only when this code verifies; until then the account is merely pending.
 *
 * The emailed code is the default method. Resend is throttled on the server
 * (`resend_in`), and the button counts that down rather than inviting clicks
 * the server will refuse.
 */
export default function MfaChallenge({ method, sent_to: sentTo, resend_in: resendIn = 0, expires_in_minutes: expiresIn }) {
    const usesEmail = method !== 'totp';
    const form = useForm({ code: '', remember: false });
    const [wait, setWait] = useState(resendIn);
    const [resending, setResending] = useState(false);
    // A refused resend redirects back with `code` errors that useForm never sees
    // (they were not its submit), so the visit's own onError carries them here.
    const [resendError, setResendError] = useState(null);

    // A fresh `resend_in` arrives with each response (e.g. after a resend).
    useEffect(() => setWait(resendIn), [resendIn]);

    useEffect(() => {
        if (wait <= 0) return undefined;
        const timer = setTimeout(() => setWait((seconds) => seconds - 1), 1000);
        return () => clearTimeout(timer);
    }, [wait]);

    const submit = (event) => {
        event.preventDefault();
        setResendError(null);
        form.post(route('tprm-portal.mfa.verify'), {
            onError: () => {
                form.reset('code');
                // Resetting disables the submit button; keep focus on the field and its error.
                document.getElementById('code')?.focus();
            },
        });
    };

    const resend = () => {
        router.post(route('tprm-portal.mfa.resend'), {}, {
            preserveScroll: true,
            onStart: () => {
                setResending(true);
                setResendError(null);
                form.clearErrors();
            },
            onError: (errors) => setResendError(errors.code ?? 'We could not send a new code just now. Please try again.'),
            onFinish: () => setResending(false),
        });
    };

    const abandon = () => router.post(route('tprm-portal.mfa.abandon'));

    return (
        <PortalGuestLayout title="Enter your code">
            <h1 className="text-lg font-semibold text-gray-900">Enter your sign-in code</h1>
            <p className="mt-1 text-sm text-gray-600">
                {usesEmail
                    ? <>We emailed a 6-digit code to <span className="font-medium text-gray-900">{sentTo}</span>. It works once and expires after {expiresIn} minutes.</>
                    : 'Enter the 6-digit code shown in your authenticator app.'}
            </p>

            <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
                <Field
                    id="code"
                    label="Sign-in code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxLength={6}
                    autoFocus
                    required
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.target.value.replace(/\D/g, '').slice(0, 6))}
                    error={form.errors.code}
                />

                <label className="flex items-center gap-2 text-sm text-gray-700">
                    <input
                        type="checkbox"
                        className="form-checkbox"
                        checked={form.data.remember}
                        onChange={(event) => form.setData('remember', event.target.checked)}
                    />
                    Keep me signed in on this device
                </label>

                <PrimaryButton disabled={form.processing || form.data.code.length !== 6}>
                    {form.processing ? 'Checking…' : 'Sign in'}
                </PrimaryButton>
            </form>

            <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                {usesEmail ? (
                    <LinkButton onClick={resend} disabled={wait > 0 || resending}>
                        {wait > 0 ? `Send a new code in ${wait}s` : 'Send a new code'}
                    </LinkButton>
                ) : <span />}
                <LinkButton onClick={abandon}>Cancel sign-in</LinkButton>
            </div>

            {resendError && (
                <p role="alert" className="mt-3 text-sm text-red-700">{resendError}</p>
            )}
        </PortalGuestLayout>
    );
}
