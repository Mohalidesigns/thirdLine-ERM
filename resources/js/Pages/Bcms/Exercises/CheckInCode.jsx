import { Head, useForm } from '@inertiajs/react';

/**
 * The SMS/short-code fallback check-in (qr-checkin spec) — the form a
 * marshal's kiosk device uses to check a stream of people in by the digits
 * they read aloud, or that a participant with no camera opens directly.
 *
 * SAME SHELL AS `CheckIn.jsx`, deliberately: no layout, no navigation, no
 * login — a participant reaching this page has exactly one thing to do.
 */
export default function CheckInCode({ error = null, code_form_url: codeFormUrl }) {
    const form = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(codeFormUrl, { preserveScroll: true });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
            <Head title="Exercise check-in — enter your code" />

            <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow">
                <p className="mb-2 text-sm font-bold uppercase tracking-wide text-violet-800">THIS IS AN EXERCISE</p>
                <h1 className="text-lg font-semibold text-slate-900">Check in with your code</h1>

                {error && (
                    <p role="alert" className="mt-3 rounded bg-rose-50 p-2 text-sm text-rose-700">{error}</p>
                )}

                <form onSubmit={submit} className="mt-4 space-y-3">
                    <div>
                        <label htmlFor="code" className="block text-sm font-medium text-slate-700">
                            Enter your check-in code
                        </label>
                        <p id="code-hint" className="text-xs text-slate-500">Ask your facilitator for this code.</p>
                        <input
                            id="code"
                            type="text"
                            inputMode="text"
                            autoCapitalize="characters"
                            aria-describedby="code-hint"
                            className="form-input mt-1 w-full text-lg tracking-widest"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                        />
                        {form.errors.code && <p role="alert" className="mt-1 text-xs text-rose-700">{form.errors.code}</p>}
                    </div>

                    <button type="submit" disabled={form.processing}
                        className="min-h-[44px] w-full rounded bg-emerald-700 py-3 text-base font-medium text-white hover:bg-emerald-600 disabled:opacity-70">
                        {form.processing ? 'Checking in…' : 'Check in'}
                    </button>
                </form>
            </div>
        </div>
    );
}
