import { Head, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { CsrfField } from '@/lib/nativeForm';

/**
 * The second factor. Posts natively: on success the server signs the user in
 * and sends them to the Blade Command Centre.
 */
export default function MfaVerify({ account }) {
    const { errors } = usePage().props;

    return (
        <GuestLayout title="Enter Verification Code" subtitle="Two-Factor Authentication">
            <Head title="Verify Two-Factor Authentication" />
            <p className="text-gray-500 text-sm mb-6">
                Enter the 6-digit code from your authenticator app{account ? <> for <span className="font-medium text-gray-700">{account}</span></> : null}.
            </p>

            <form method="POST" action={route('mfa.verify')} className="space-y-5">
                <CsrfField />
                <div>
                    <label htmlFor="code" className="block text-sm font-medium text-gray-700 mb-3">Verification Code</label>
                    <input
                        id="code"
                        name="code"
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={6}
                        autoComplete="one-time-code"
                        placeholder="000000"
                        required
                        autoFocus
                        className="w-full text-center text-3xl font-mono tracking-[0.5em] border-2 border-gray-300 rounded-lg py-3 focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 transition"
                    />
                    <InputError message={errors?.code} className="mt-2 text-center" />
                    <p className="text-xs text-gray-500 mt-2 text-center">From your authenticator app</p>
                </div>

                <button type="submit" className="w-full bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] text-white font-semibold py-2.5 rounded-lg transition duration-200">
                    Verify
                </button>
            </form>

            <div className="mt-6 border-t border-gray-200 pt-6 space-y-3">
                <p className="text-center text-xs text-gray-500">
                    Lost access to your authenticator? Contact your system administrator to reset MFA on your account.
                </p>
                <form method="POST" action={route('logout')} className="text-center">
                    <CsrfField />
                    <button type="submit" className="text-xs text-gray-400 hover:text-gray-600 underline">Cancel and sign out</button>
                </form>
            </div>
        </GuestLayout>
    );
}
