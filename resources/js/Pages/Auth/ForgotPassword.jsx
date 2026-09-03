import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <GuestLayout title="Reset Password" icon="lock_reset" status={status}>
            <Head title="Forgot Password" />
            <p className="text-gray-500 text-sm mb-6">Enter your email address and we'll send you a password reset link.</p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                    <div className="relative">
                        <span className="absolute left-3 top-3 material-symbols-outlined text-gray-400 text-[20px]">mail</span>
                        <input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoFocus
                            placeholder="you@example.com"
                            className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:border-[var(--color-primary)] transition"
                        />
                    </div>
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <button type="submit" disabled={processing} className="w-full bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] text-white font-semibold py-2.5 rounded-lg transition duration-200 disabled:opacity-60">
                    {processing ? 'Sending…' : 'Send Reset Link'}
                </button>
            </form>

            <div className="mt-6 text-center">
                <Link href={route('login')} className="text-sm text-[var(--color-primary)] hover:opacity-80 font-medium">Back to Login</Link>
            </div>
        </GuestLayout>
    );
}
