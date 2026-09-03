import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * App-wide license surface, mounted globally in app.jsx so it renders on every page:
 *   - license.blocked   → full-screen, non-dismissible "license ended" flash card that
 *                         stops the whole app (revoked / unlicensed / locked / integrity).
 *   - license.warning   → a dismissible bottom-right card. Amber (≈2 months out) is a
 *                         persistent reminder; red (≈1 month, or a demo's final days) is
 *                         shown at most once per calendar day.
 *
 * Super Admins always keep a route to /settings/license to recover; that page is never
 * covered by the block so a license can be re-activated.
 */

const Icon = ({ d, className = 'w-6 h-6', strokeWidth = 1.5 }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" strokeWidth={strokeWidth} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d={d} />
    </svg>
);

const PATH = {
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
    alert: 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
    clock: 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
    x: 'M6 18L18 6M6 6l12 12',
};

const BLOCK_COPY = {
    revoked: {
        title: 'License revoked',
        detail: 'This installation’s license has been revoked by the license administrator. The application is locked until a valid license is activated.',
    },
    unlicensed: {
        title: 'No active license',
        detail: 'No active ThirdLine license was found for this installation. The application is locked until a license is activated.',
    },
    expired: {
        title: 'License expired',
        detail: 'Your ThirdLine license has expired. The application is locked until it is renewed.',
    },
    integrity: {
        title: 'License integrity check failed',
        detail: 'The license could not be verified on this system. The application is locked. Re-activate the license to continue.',
    },
    device: {
        title: 'License not valid for this device',
        detail: 'This license is bound to a different device. The application is locked until it is re-activated here.',
    },
    locked: {
        title: 'License unavailable',
        detail: 'Your ThirdLine license could not be confirmed and the application is locked. Re-activate the license to continue.',
    },
};

function todayStamp() {
    // Local calendar day, e.g. "2026-06-24" — used to throttle the red alert to once/day.
    return new Date().toISOString().slice(0, 10);
}

function LicenseBlock({ block_reason }) {
    const { props, component } = usePage();
    const permissions = props?.auth?.permissions ?? [];
    const isSuperAdmin = permissions.includes('license.manage');

    // Never cover the License settings page itself — that is the recovery surface.
    if (component === 'Settings/License') {
        return null;
    }

    const copy = BLOCK_COPY[block_reason] ?? BLOCK_COPY.locked;

    return (
        <div
            className="fixed inset-0 z-[2147483646] flex items-center justify-center bg-slate-900/70 p-4 backdrop-blur-sm"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="license-block-title"
        >
            <div className="w-full max-w-md rounded-2xl bg-white p-8 text-center shadow-2xl ring-1 ring-black/5">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <Icon d={PATH.lock} className="h-7 w-7" strokeWidth={1.75} />
                </div>
                <h2 id="license-block-title" className="mt-5 text-xl font-semibold text-slate-900">
                    {copy.title}
                </h2>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{copy.detail}</p>

                <div className="mt-7 flex flex-col gap-3">
                    {isSuperAdmin ? (
                        <Link
                            href={route('admin.license')}
                            className="inline-flex w-full items-center justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800"
                        >
                            Go to License settings
                        </Link>
                    ) : (
                        <p className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500">
                            Please contact your administrator to restore access.
                        </p>
                    )}
                    <button
                        type="button"
                        onClick={() => router.post(route('logout'))}
                        className="inline-flex w-full items-center justify-center rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    >
                        Sign out
                    </button>
                </div>
            </div>
        </div>
    );
}

function LicenseWarning({ warning }) {
    const isRed = warning.level === 'red';
    const isDaily = warning.cadence === 'daily';
    // A stable key per expiry so a renewed license starts a fresh alert cycle.
    const storageKey = `tl_license_alert_${isRed ? 'red' : 'amber'}_${warning.expires_at ?? 'na'}`;

    const [visible, setVisible] = useState(() => {
        if (typeof window === 'undefined') return true;
        if (!isDaily) return true; // amber: persistent (reappears each load until dismissed in-session)
        try {
            return window.localStorage.getItem(storageKey) !== todayStamp();
        } catch {
            return true;
        }
    });

    useEffect(() => {
        // If the warning identity changes (renewal, tier change), re-evaluate visibility.
        if (!isDaily) {
            setVisible(true);
            return;
        }
        try {
            setVisible(window.localStorage.getItem(storageKey) !== todayStamp());
        } catch {
            setVisible(true);
        }
    }, [storageKey, isDaily]);

    if (!visible) return null;

    const dismiss = () => {
        if (isDaily) {
            try {
                window.localStorage.setItem(storageKey, todayStamp());
            } catch {
                /* ignore storage failures */
            }
        }
        setVisible(false);
    };

    const accent = isRed
        ? { card: 'border-l-red-500', chip: 'bg-red-100 text-red-600', title: 'text-red-800' }
        : { card: 'border-l-amber-400', chip: 'bg-amber-100 text-amber-600', title: 'text-amber-800' };

    return (
        <div className="fixed bottom-4 right-4 z-[2147483645] w-[calc(100%-2rem)] max-w-sm sm:w-full">
            <div className={`rounded-xl border border-slate-200 border-l-4 bg-white p-4 shadow-lg ring-1 ring-black/5 ${accent.card}`}>
                <div className="flex items-start gap-3">
                    <div className={`mt-0.5 flex h-8 w-8 flex-none items-center justify-center rounded-full ${accent.chip}`}>
                        <Icon d={isRed ? PATH.alert : PATH.clock} className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className={`text-sm font-semibold ${accent.title}`}>{warning.title}</p>
                        <p className="mt-1 text-sm leading-relaxed text-slate-600">{warning.message}</p>
                        <Link
                            href={route('admin.license')}
                            className="mt-2 inline-block text-sm font-medium text-slate-900 underline-offset-2 hover:underline"
                        >
                            View license
                        </Link>
                    </div>
                    <button
                        type="button"
                        onClick={dismiss}
                        aria-label="Dismiss"
                        className="flex-none rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                    >
                        <Icon d={PATH.x} className="h-4 w-4" strokeWidth={2} />
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function LicenseNotice() {
    const license = usePage().props?.license;

    if (!license) return null;
    if (license.blocked) return <LicenseBlock block_reason={license.block_reason} />;
    if (license.warning) return <LicenseWarning warning={license.warning} />;
    return null;
}
