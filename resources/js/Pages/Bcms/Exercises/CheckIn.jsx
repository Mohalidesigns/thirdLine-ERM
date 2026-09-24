import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

/**
 * The unauthenticated check-in page (qr-checkin spec, screen B) — built from
 * `CallTrees/Acknowledge.jsx`'s exact template: no layout, no navigation, no
 * login, POST → redirect → GET so a refresh cannot double-submit.
 *
 * "THIS IS AN EXERCISE" IS THE CARD'S OWN FIRST LINE OF TEXT, not a banner,
 * because this page has no layout to put a banner in — the rule
 * ("unmistakable, not a footnote") applies to the surface, not to a specific
 * component (spec §2).
 */
export default function CheckIn({ ok, done = false, headline, detail, name, exercise, site, check_in_url: checkInUrl }) {
    const [state, setState] = useState('idle'); // idle | checking | failed
    const buttonRef = useRef(null);

    useEffect(() => {
        buttonRef.current?.focus();
    }, []);

    const checkIn = () => {
        setState('checking');
        router.post(checkInUrl, {}, {
            onError: () => setState('failed'),
        });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
            <Head title="Exercise check-in" />

            <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow">
                {ok && !done && (
                    <p className="mb-2 text-sm font-bold uppercase tracking-wide text-violet-800">THIS IS AN EXERCISE</p>
                )}

                <h1 className={`text-lg font-semibold ${ok ? 'text-slate-900' : 'text-slate-600'}`}>
                    {done ? headline : (exercise ? `Checking in for ${exercise}` : headline)}
                </h1>

                {name && <p className="mt-1 text-sm text-slate-500">{name}</p>}
                {detail && <p className="mt-4 text-sm text-slate-600">{detail}</p>}
                {site && !done && <p className="mt-2 text-xs text-slate-500">{site}</p>}

                {ok && !done && (
                    <button
                        ref={buttonRef}
                        type="button"
                        disabled={state === 'checking'}
                        onClick={checkIn}
                        className="mt-6 min-h-[44px] w-full rounded bg-emerald-700 py-3 text-base font-medium text-white hover:bg-emerald-600 disabled:opacity-70"
                    >
                        {state === 'checking' ? 'Checking in…' : "I'm here"}
                    </button>
                )}

                {state === 'failed' && (
                    <div className="mt-4 space-y-2">
                        <p role="alert" className="text-sm text-rose-700">
                            Something went wrong — try again, or tell the marshal you are here.
                        </p>
                        <button type="button" onClick={checkIn}
                            className="w-full rounded border border-slate-300 py-2 text-sm hover:bg-slate-50">
                            Try again
                        </button>
                    </div>
                )}

                {done && (
                    <div role="status" className="mt-6 rounded bg-emerald-50 p-3 text-sm text-emerald-800">
                        {headline}
                    </div>
                )}

                {!ok && !done && (
                    <p className="mt-4 text-xs text-slate-500">
                        If you have a short code, you can also check in at any browser without a camera.
                    </p>
                )}
            </div>
        </div>
    );
}
