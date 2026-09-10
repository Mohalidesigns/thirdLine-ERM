import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@thirdline/ui/Components/InputError';
import { CsrfField } from '@thirdline/ui/lib/nativeForm';

const FEATURES = ['COSO ERM informed', 'Designed for CBN ORMS', 'ISO 31000 informed', 'Basel III-aligned taxonomy'];

const inputClass = 'w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:border-[var(--color-primary)] transition';

/**
 * Sign in. Posts natively (see lib/nativeForm.js): a successful sign-in lands
 * on the Blade Command Centre until Phase 5 ports it.
 */
export default function Login({ status, canResetPassword, ssoAvailable }) {
    const { errors, flash, old } = usePage().props;
    const [showPassword, setShowPassword] = useState(false);

    const banners = [
        status && ['green', status],
        flash?.success && ['green', flash.success],
        flash?.error && ['red', flash.error],
        flash?.warning && ['yellow', flash.warning],
    ].filter(Boolean);

    const tones = {
        green: 'bg-green-50 border-green-200 text-green-700',
        red: 'bg-red-50 border-red-200 text-red-700',
        yellow: 'bg-yellow-50 border-yellow-200 text-yellow-700',
    };

    return (
        <>
            <Head title="Sign In" />
            <div className="min-h-screen grid lg:grid-cols-2 bg-gray-50">
                {/* Brand panel */}
                <div className="relative bg-[#0F2544] text-white p-10 lg:p-14 flex flex-col justify-between overflow-hidden">
                    <div
                        className="absolute inset-0 opacity-[0.08] pointer-events-none"
                        style={{
                            backgroundImage:
                                'radial-gradient(circle at 20% 20%, #ffffff 1px, transparent 1px), radial-gradient(circle at 80% 60%, #ffffff 1px, transparent 1px)',
                            backgroundSize: '48px 48px, 64px 64px',
                        }}
                    />
                    <div className="relative flex items-center gap-3">
                        <div className="w-12 h-12 rounded-lg bg-[var(--color-accent)] flex items-center justify-center shadow-md">
                            <span className="material-symbols-outlined text-[var(--color-primary)]" style={{ fontSize: 28 }}>verified_user</span>
                        </div>
                        <div>
                            <h1 className="text-xl font-bold leading-tight">Atheris ERM</h1>
                            <p className="text-xs text-white/70">GRC Suite</p>
                        </div>
                    </div>

                    <div className="relative mt-16 lg:mt-0">
                        <h2 className="text-3xl lg:text-4xl font-bold leading-tight">Enterprise Risk Management</h2>
                        <p className="mt-4 text-sm lg:text-base text-white/80 max-w-lg leading-relaxed">
                            Enterprise-grade governance, risk, and compliance platform built for the African market. Manage risks, ensure compliance, and protect your organization.
                        </p>
                        <div className="mt-8 grid grid-cols-2 gap-y-3 gap-x-6 max-w-md">
                            {FEATURES.map((feature) => (
                                <div key={feature} className="flex items-center gap-2 text-sm">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[var(--color-accent)] flex-shrink-0" />
                                    <span className="text-white/90">{feature}</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="relative mt-12 lg:mt-0">
                        <p className="text-xs text-white/50">&copy; {new Date().getFullYear()} Atheris ERM GRC Suite. All rights reserved.</p>
                    </div>
                </div>

                {/* Form panel */}
                <div className="flex items-center justify-center p-6 lg:p-14">
                    <div className="w-full max-w-md">
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-8 lg:p-10">
                            <h2 className="text-2xl font-bold text-gray-900">Sign In</h2>
                            <p className="text-sm text-gray-500 mt-1">Access your GRC dashboard</p>

                            {banners.map(([tone, text], i) => (
                                <div key={i} className={`mt-6 p-3 rounded-lg border text-sm ${tones[tone]}`}>{text}</div>
                            ))}

                            <form method="POST" action={route('login')} className="mt-6 space-y-5">
                                <CsrfField />

                                <div>
                                    <label htmlFor="email" className="block text-sm font-medium text-gray-800 mb-1.5">Email Address</label>
                                    <input id="email" type="email" name="email" defaultValue={old?.email || ''} required autoFocus autoComplete="username" className={inputClass} />
                                    <InputError message={errors?.email} className="mt-1.5" />
                                </div>

                                <div>
                                    <label htmlFor="password" className="block text-sm font-medium text-gray-800 mb-1.5">Password</label>
                                    <div className="relative">
                                        <input id="password" type={showPassword ? 'text' : 'password'} name="password" required autoComplete="current-password" className={`${inputClass} pr-10`} />
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword(!showPassword)}
                                            className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                                            aria-label={showPassword ? 'Hide password' : 'Show password'}
                                        >
                                            <span className="material-symbols-outlined text-[18px]">{showPassword ? 'visibility_off' : 'visibility'}</span>
                                        </button>
                                    </div>
                                    <InputError message={errors?.password} className="mt-1.5" />
                                </div>

                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="remember" value="1" className="form-checkbox w-4 h-4 rounded border-gray-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                        <span className="text-sm text-gray-600">Remember me</span>
                                    </label>
                                    {canResetPassword && (
                                        <Link href={route('password.request')} className="text-sm text-[var(--color-primary)] hover:opacity-80 font-semibold">
                                            Forgot password?
                                        </Link>
                                    )}
                                </div>

                                <button type="submit" className="w-full bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] text-white font-semibold py-3 rounded-lg transition duration-200">
                                    Sign In
                                </button>
                            </form>

                            {ssoAvailable && (
                                <div className="mt-6">
                                    <div className="relative">
                                        <div className="absolute inset-0 flex items-center"><div className="w-full border-t border-gray-200" /></div>
                                        <div className="relative flex justify-center"><span className="bg-white px-3 text-xs text-gray-400">or</span></div>
                                    </div>
                                    {/* The organization is identified from the email domain, so
                                        staff never need to know their organization's sign-in URL.
                                        Native: the server answers with a redirect to the identity provider. */}
                                    <form method="POST" action={route('sso.discover')} className="mt-6 space-y-3">
                                        <CsrfField />
                                        <label htmlFor="sso_email" className="block text-sm font-medium text-gray-800">Sign in with your organization</label>
                                        <input id="sso_email" type="email" name="email" defaultValue={old?.email || ''} placeholder="you@yourcompany.com" required className={inputClass} />
                                        <button type="submit" className="w-full border border-[var(--color-primary)] text-[var(--color-primary)] hover:bg-[var(--color-primary)]/5 font-semibold py-2.5 rounded-lg transition duration-200">
                                            Continue with single sign-on
                                        </button>
                                    </form>
                                </div>
                            )}

                            <p className="mt-6 text-center text-sm text-gray-500">
                                Don't have an account? Ask your risk administrator to create one.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
