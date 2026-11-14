import { Head, usePage } from '@inertiajs/react';

/**
 * The vendor portal's signed-out shell — sign-in, MFA and invitations.
 *
 * Like PortalLayout it wears the client's name and never ours: a vendor
 * signing in for one of four banks needs to see which bank, and a sign-in page
 * that announced the platform would tell every visitor which system that bank
 * runs. Where no client is known (the bare entry page) it names nobody.
 */
export default function PortalGuestLayout({ title, clientName = null, children }) {
    const { flash = {} } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col items-center bg-gray-50 px-4 py-10 sm:justify-center">
            <Head title={title ? (clientName ? `${title} — ${clientName}` : title) : (clientName ?? 'Vendor portal')} />

            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    {clientName && <p className="text-base font-semibold text-gray-900">{clientName}</p>}
                    <p className="text-sm text-gray-500">Vendor portal</p>
                </div>

                {flash.success && (
                    <div role="status" className="mb-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-900">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div role="alert" className="mb-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900">
                        {flash.error}
                    </div>
                )}

                <main className="rounded-lg border border-gray-200 bg-white px-6 py-6 shadow-sm">
                    {children}
                </main>
            </div>
        </div>
    );
}

/** A labelled input with its error wired to it, so a screen reader hears both. */
export function Field({ id, label, error, hint, ...input }) {
    const describedBy = [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    return (
        <div>
            <label htmlFor={id} className="block text-sm font-medium text-gray-700">{label}</label>
            <input
                id={id}
                name={id}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={describedBy}
                className={`mt-1 block w-full rounded border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                    error ? 'border-red-400' : 'border-gray-300'
                }`}
                {...input}
            />
            {hint && <p id={`${id}-hint`} className="mt-1 text-xs text-gray-500">{hint}</p>}
            {error && <p id={`${id}-error`} className="mt-1 text-sm text-red-700">{error}</p>}
        </div>
    );
}

export function PrimaryButton({ children, ...props }) {
    return (
        <button
            type="submit"
            className="w-full rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:opacity-60"
            {...props}
        >
            {children}
        </button>
    );
}

export function LinkButton({ children, ...props }) {
    return (
        <button
            type="button"
            className="text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:no-underline disabled:opacity-60"
            {...props}
        >
            {children}
        </button>
    );
}
