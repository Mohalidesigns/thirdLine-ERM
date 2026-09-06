import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';

export default function Index({ term, results }) {
    const [q, setQ] = useState(term || '');

    const submit = (e) => {
        e.preventDefault();
        router.get(route('search.index'), { q }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout title={term ? `“${term}”` : 'Search'}>
            <Head title="Search" />
            <div className="mx-auto max-w-3xl">
                <form onSubmit={submit} className="mb-4" role="search">
                    <div className="relative">
                        <span className="material-symbols-outlined pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">search</span>
                        <input
                            type="search"
                            name="q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            autoFocus
                            placeholder="Search everything — risks, controls, issues, losses, KRIs, units…"
                            className="w-full rounded-xl border border-gray-200 py-3 pl-11 pr-4 text-sm shadow-sm focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)]"
                        />
                    </div>
                </form>

                {term !== '' && (
                    <>
                        <p className="mb-3 text-xs text-gray-500">
                            {results.length} {results.length === 1 ? 'result' : 'results'}
                        </p>
                        <ul className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                            {results.length === 0 && (
                                <li className="px-4 py-10 text-center text-sm text-gray-400">Nothing matched “{term}” within what you have permission to see.</li>
                            )}
                            {results.map((result) => (
                                <li key={result.id}>
                                    {/* Plain anchor: most destinations are Blade pages until their module is ported. */}
                                    <a href={result.url} className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50">
                                        <span className="material-symbols-outlined shrink-0 rounded-lg bg-gray-100 p-2 text-[20px] leading-none text-gray-500">{result.icon || 'topic'}</span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-gray-800">{result.name}</span>
                                            <span className="block truncate text-xs text-gray-400">
                                                {result.type}
                                                {result.code ? ` · ${result.code}` : ''}
                                                {result.description ? ` — ${result.description}` : ''}
                                            </span>
                                        </span>
                                        <span className="material-symbols-outlined shrink-0 text-[18px] text-gray-300">chevron_right</span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
