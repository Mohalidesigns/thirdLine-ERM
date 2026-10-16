import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import Icon from '@thirdline/ui/Components/Icon';

export default function Index({ term, results }) {
    const [q, setQ] = useState(term || '');

    const submit = (e) => {
        e.preventDefault();
        router.get(route('search.index'), { q }, { preserveState: true });
    };

    return (
        <AuthenticatedLayout title={term ? `“${term}”` : 'Search'}>
            <Head title="Search" />
            <PageHeader title="Search" subtitle={term ? `Results for “${term}”` : 'Risks, controls, issues, losses, KRIs and units in one place'} />
            <div className="mx-auto max-w-3xl">
                <form onSubmit={submit} className="mb-4" role="search">
                    <div className="relative">
                        <Icon name="search" className="w-5 h-5 shrink-0 pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                        <input
                            type="search"
                            name="q"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            autoFocus
                            placeholder="Search everything — risks, controls, issues, losses, KRIs, units…"
                            aria-label="Search"
                            className="form-input w-full py-3 pl-11 pr-4 text-sm"
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
                                        <Icon name={result.icon || 'topic'} className="w-5 h-5 shrink-0 rounded-lg bg-gray-100 p-2 text-gray-500" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-gray-800">{result.name}</span>
                                            <span className="block truncate text-xs text-gray-400">
                                                {result.type}
                                                {result.code ? ` · ${result.code}` : ''}
                                                {result.description ? ` — ${result.description}` : ''}
                                            </span>
                                        </span>
                                        <Icon name="chevron_right" className="w-4.5 h-4.5 shrink-0 text-gray-300" />
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
