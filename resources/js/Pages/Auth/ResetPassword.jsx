import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';

const inputClass = 'w-full px-4 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:border-[var(--color-primary)] transition';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email: email || '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('password.update'), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title="Choose a new password" icon="lock_reset">
            <Head title="Reset Password" />
            <p className="text-gray-500 text-sm mb-6">At least 12 characters, with upper and lower case, a number and a symbol.</p>

            <form onSubmit={submit} className="space-y-5">
                <InputError message={errors.token} />
                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                    <input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required autoComplete="username" className={inputClass} />
                    <InputError message={errors.email} className="mt-2" />
                </div>
                <div>
                    <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                    <input id="password" type="password" value={data.password} onChange={(e) => setData('password', e.target.value)} required autoFocus autoComplete="new-password" className={inputClass} />
                    <InputError message={errors.password} className="mt-2" />
                </div>
                <div>
                    <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                    <input id="password_confirmation" type="password" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} required autoComplete="new-password" className={inputClass} />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <button type="submit" disabled={processing} className="w-full bg-[var(--color-primary)] hover:bg-[var(--color-primary-light)] text-white font-semibold py-2.5 rounded-lg transition duration-200 disabled:opacity-60">
                    {processing ? 'Saving…' : 'Reset Password'}
                </button>
            </form>

            <div className="mt-6 text-center">
                <Link href={route('login')} className="text-sm text-[var(--color-primary)] hover:opacity-80 font-medium">Back to Login</Link>
            </div>
        </GuestLayout>
    );
}
