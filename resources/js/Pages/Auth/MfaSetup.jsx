import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import QrCode from '@/Components/QrCode';
import { CsrfField } from '@/lib/nativeForm';

const STEPS = [
    'Download an authenticator app such as Google Authenticator, Microsoft Authenticator or Authy.',
    'Scan the QR code with the app, or type the manual entry key.',
    'Enter the 6-digit code the app shows to confirm.',
];

/**
 * Enrolment. The QR code is drawn here in the browser from the otpauth:// URI
 * the server generated — the secret never leaves this deployment.
 */
export default function MfaSetup({ secret, otpauthUri, issuer, account, alreadyEnabled }) {
    const { errors, flash } = usePage().props;

    return (
        <AuthenticatedLayout title="Two-Factor Authentication">
            <Head title="Two-Factor Authentication Setup" />

            <div className="max-w-3xl mx-auto">
                {flash?.warning && (
                    <div className="mb-4 p-3 rounded-lg border bg-yellow-50 border-yellow-200 text-yellow-800 text-sm">{flash.warning}</div>
                )}
                {alreadyEnabled && (
                    <div className="mb-4 p-3 rounded-lg border bg-blue-50 border-blue-200 text-blue-800 text-sm">
                        Two-factor authentication is already enabled on this account. Completing this form re-enrols it with a new key.
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="space-y-6">
                        <div className="card">
                            <div className="card-body">
                                <h3 className="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                                    <span className="material-symbols-outlined text-[var(--color-primary)]">info</span>
                                    Setup Instructions
                                </h3>
                                <ol className="space-y-3 text-sm text-gray-700">
                                    {STEPS.map((step, i) => (
                                        <li key={i} className="flex gap-3">
                                            <span className="flex-shrink-0 w-6 h-6 rounded-full bg-[var(--color-primary)] text-white flex items-center justify-center text-xs font-bold">{i + 1}</span>
                                            <span>{step}</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-body">
                                <h3 className="text-sm font-semibold text-gray-900 mb-2">Manual Entry (if the QR code does not scan)</h3>
                                <p className="text-xs text-gray-500 mb-2">Enter this key in your authenticator app for <span className="font-medium">{issuer}</span> ({account}):</p>
                                <div className="bg-gray-50 rounded p-3 font-mono text-sm text-gray-700 break-all border border-gray-200 select-all" data-testid="mfa-secret">
                                    {secret}
                                </div>
                                <p className="text-xs text-gray-400 mt-2">Time-based, SHA-1, 6 digits, 30-second step.</p>
                            </div>
                        </div>
                    </div>

                    <div className="space-y-6">
                        <div className="card">
                            <div className="card-body flex flex-col items-center">
                                <h3 className="text-base font-semibold text-gray-900 mb-4">Scan QR Code</h3>
                                <QrCode value={otpauthUri} size={200} label={`Authenticator enrolment code for ${account}`} />
                                <p className="text-xs text-gray-500 text-center mt-4">
                                    Generated on this server and drawn in your browser. Your key is never sent to any external service.
                                </p>
                            </div>
                        </div>

                        <div className="card">
                            <div className="card-body">
                                <h3 className="text-base font-semibold text-gray-900 mb-4">Verify Setup</h3>
                                <form method="POST" action={route('mfa.enable')} className="space-y-4">
                                    <CsrfField />
                                    <div>
                                        <label htmlFor="code" className="block text-sm font-medium text-gray-700 mb-2">Enter 6-Digit Code</label>
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
                                            className="w-full text-center text-2xl font-mono tracking-[0.5em] border-2 border-gray-300 rounded-lg py-3 focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 transition"
                                        />
                                        <InputError message={errors?.code} className="mt-2" />
                                        <p className="text-xs text-gray-500 mt-2">Get this from your authenticator app</p>
                                    </div>
                                    <button type="submit" className="w-full bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] text-white font-semibold py-2.5 rounded-lg transition duration-200">
                                        Verify &amp; Enable
                                    </button>
                                </form>
                                <div className="mt-4 flex items-center justify-between text-sm">
                                    <Link href={route('profile.edit')} className="text-gray-500 hover:text-gray-700">Back to profile</Link>
                                    {/* Plain anchor: the Command Centre is a Blade page until Phase 5. */}
                                    <a href="/risk/dashboard" className="text-gray-500 hover:text-gray-700">Skip for now</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
