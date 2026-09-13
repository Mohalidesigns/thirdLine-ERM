import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

/**
 * Global search type-ahead for the top bar (WP-08 TASK 6). Calls the JSON
 * suggest endpoint — results are permission-filtered server-side — and
 * Enter opens the full results page. Result links are plain anchors: most
 * destinations are Blade pages until their module is ported.
 */
export default function SearchBox() {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const box = useRef(null);
    const timer = useRef(null);

    useEffect(() => {
        const onClick = (e) => {
            if (box.current && !box.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    const suggest = (value) => {
        clearTimeout(timer.current);
        if (value.trim().length < 2) {
            setResults([]);
            setOpen(false);
            return;
        }
        timer.current = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('search.suggest'), { params: { q: value }, headers: { Accept: 'application/json' } });
                setResults(data.results ?? []);
                setOpen(true);
            } catch {
                setResults([]);
            }
        }, 300);
    };

    const submit = (e) => {
        e.preventDefault();
        if (term.trim() === '') return;
        setOpen(false);
        router.visit(route('search.index', { q: term.trim() }));
    };

    return (
        <div className="relative" ref={box}>
            <form onSubmit={submit} role="search">
                <span className="material-symbols-outlined pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-[18px] text-gray-400">search</span>
                <input
                    type="search"
                    value={term}
                    onChange={(e) => {
                        setTerm(e.target.value);
                        suggest(e.target.value);
                    }}
                    onFocus={() => term.trim().length >= 2 && results.length > 0 && setOpen(true)}
                    placeholder="Search risks, controls, units…"
                    aria-label="Search"
                    className="w-56 rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-8 pr-2 text-xs focus:border-[var(--color-primary)] focus:bg-white focus:ring-1 focus:ring-[var(--color-primary)] lg:w-72"
                />
            </form>

            {open && results.length > 0 && (
                <div className="absolute right-0 top-full z-50 mt-1 w-96 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl">
                    <ul className="max-h-96 divide-y divide-gray-50 overflow-auto">
                        {results.map((result) => (
                            <li key={result.id}>
                                <a href={result.url} className="flex items-center gap-2.5 px-3 py-2 hover:bg-gray-50">
                                    <span className="material-symbols-outlined shrink-0 text-[18px] text-gray-400">{result.icon || 'topic'}</span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-xs font-medium text-gray-800">{result.name}</span>
                                        <span className="block truncate text-[10px] text-gray-400">
                                            {result.type}
                                            {result.code ? ` · ${result.code}` : ''}
                                        </span>
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                    <button type="button" onClick={submit} className="block w-full border-t border-gray-100 px-3 py-2 text-center text-[11px] font-medium text-[var(--color-primary)] hover:bg-gray-50">
                        All results →
                    </button>
                </div>
            )}
        </div>
    );
}
