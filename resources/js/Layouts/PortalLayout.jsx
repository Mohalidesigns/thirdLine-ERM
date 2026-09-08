import { Head, Link, router, usePage } from '@inertiajs/react';

/**
 * The vendor portal's shell — minimal Atheris footprint (Phase 8, part 10).
 *
 * IT WEARS THE CLIENT'S NAME, NOT OURS. A vendor answering questionnaires for
 * four banks needs to know which one is asking; and a portal that announced
 * its platform would tell every vendor of every client which system that
 * client runs, which is reconnaissance we have no reason to hand out.
 *
 * Tenant branding comes through the CSS variables the root view already sets,
 * so a client's colours apply here with no per-page work.
 */
export default function PortalLayout({ title, children }) {
    const { auth = {}, client = {}, flash = {} } = usePage().props;

    const nav = [
        ['tprm-portal.dashboard', 'Overview'],
        ['tprm-portal.assessments.index', 'Assessments'],
        ['tprm-portal.findings.index', 'Findings'],
        ['tprm-portal.trust-profile.show', 'Trust profile'],
        ['tprm-portal.sharing.index', 'Sharing'],
    ];

    return (
        <div className="min-h-screen bg-gray-50">
            <Head title={title ? `${title} — ${client.name ?? 'Vendor Portal'}` : client.name} />

            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-gray-900">{client.name}</p>
                        <p className="truncate text-xs text-gray-500">Vendor portal · {auth.user?.vendor}</p>
                    </div>

                    <div className="flex items-center gap-3 text-sm">
                        <span className="hidden text-gray-500 sm:inline">{auth.user?.email}</span>
                        <button
                            type="button"
                            className="text-gray-600 underline hover:text-gray-900"
                            onClick={() => router.post(route('tprm-portal.logout'))}
                        >
                            Sign out
                        </button>
                    </div>
                </div>

                <nav className="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4">
                    {nav.map(([name, label]) => {
                        const href = route(name);
                        const active = typeof window !== 'undefined' && window.location.pathname === new URL(href, window.location.origin).pathname;

                        return (
                            <Link
                                key={name}
                                href={href}
                                className={`whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium ${
                                    active
                                        ? 'border-blue-600 text-blue-700'
                                        : 'border-transparent text-gray-500 hover:text-gray-700'
                                }`}
                            >
                                {label}
                            </Link>
                        );
                    })}
                </nav>
            </header>

            <main className="mx-auto max-w-6xl px-4 py-6">
                {flash.success && (
                    <div className="mb-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-900">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="mb-4 rounded border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-900">
                        {flash.error}
                    </div>
                )}

                {children}
            </main>

            <footer className="mx-auto max-w-6xl px-4 pb-8 text-xs text-gray-400">
                Questions about what is being asked go to your contact at {client.name}, through the message
                thread on the assessment so the answer stays on the record.
            </footer>
        </div>
    );
}
