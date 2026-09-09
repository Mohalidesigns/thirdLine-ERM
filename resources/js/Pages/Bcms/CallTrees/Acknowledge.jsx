import { Head, router } from '@inertiajs/react';

/**
 * The page the link in a cascade message opens.
 *
 * NO LAYOUT, NO NAVIGATION, NO LOGIN. The person opening this is on a phone, at
 * three in the morning, and has one thing to do. Everything else on the page is
 * something between them and doing it.
 *
 * THE EXERCISE PREFIX IS THE FIRST THING ON THE SCREEN, not a footnote. A
 * cascade test that somebody mistakes for a real activation is a bank
 * evacuating a branch for nothing.
 */
export default function Acknowledge({ ok, done = false, headline, detail, name, role, token }) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
            <Head title="Call tree acknowledgement" />

            <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow">
                <h1 className={`text-lg font-semibold ${ok ? 'text-slate-900' : 'text-slate-600'}`}>
                    {headline}
                </h1>

                {name && (
                    <p className="mt-1 text-sm text-slate-500">
                        {name}{role ? ` · ${role}` : ''}
                    </p>
                )}

                <p className="mt-4 text-sm text-slate-600">{detail}</p>

                {ok && !done && (
                    <button
                        type="button"
                        onClick={() => router.post(`/bcms/cascade/${token}`)}
                        className="mt-6 w-full rounded bg-emerald-700 py-3 text-base font-medium text-white hover:bg-emerald-600"
                    >
                        I received this
                    </button>
                )}

                {done && (
                    <div className="mt-6 rounded bg-emerald-50 p-3 text-sm text-emerald-800">
                        Recorded. You can close this page.
                    </div>
                )}
            </div>
        </div>
    );
}
