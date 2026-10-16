import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useId, useRef, useState } from 'react';
import Icon from '@thirdline/ui/Components/Icon';

/**
 * Global search type-ahead for the top bar (WP-08 TASK 6). Calls the JSON
 * suggest endpoint - results are permission-filtered server-side - and
 * Enter opens the full results page. Result links are plain anchors: most
 * destinations are Blade pages until their module is ported.
 *
 * States: idle, typing (<2 chars: panel closed), loading (aria-busy), results,
 * no results, error. A failed request never throws; Enter still navigates.
 */
export default function SearchBox() {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState([]);
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const [asked, setAsked] = useState('');
    const box = useRef(null);
    const timer = useRef(null);
    const seq = useRef(0);
    const panelId = useId();

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
        const mine = ++seq.current;
        if (value.trim().length < 2) {
            setResults([]);
            setFailed(false);
            setBusy(false);
            setOpen(false);
            return;
        }
        setBusy(true);
        timer.current = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('search.suggest'), { params: { q: value }, headers: { Accept: 'application/json' } });
                if (mine !== seq.current) return;
                setResults(data.results ?? []);
                setFailed(false);
            } catch {
                if (mine !== seq.current) return;
                setResults([]);
                setFailed(true);
            }
            setAsked(value.trim());
            setBusy(false);
            setOpen(true);
        }, 300);
    };

    const submit = (e) => {
        e.preventDefault();
        if (term.trim() === '') return;
        setOpen(false);
        router.visit(route('search.index', { q: term.trim() }));
    };

    const showPanel = open && !busy && term.trim().length >= 2;
    const status = failed ? '' : results.length > 0 ? `${results.length} result${results.length === 1 ? '' : 's'}` : `No results for \u201c${asked}\u201d`;

    return (
        <div className="relative" ref={box}>
            <form onSubmit={submit} role="search">
                <Icon name="search" className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                <input
                    type="search"
                    value={term}
                    onChange={(e) => {
                        setTerm(e.target.value);
                        suggest(e.target.value);
                    }}
                    onFocus={() => term.trim().length >= 2 && (results.length > 0 || failed) && setOpen(true)}
                    placeholder="Search risks, controls, units…"
                    aria-label="Search"
                    aria-expanded={showPanel}
                    aria-controls={panelId}
                    aria-busy={busy}
                    className="form-input w-56 xl:w-80 pl-10 pr-4 py-2 text-sm bg-gray-50 border-[#e5e7eb] focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20"
                />
            </form>

            <div id={panelId} hidden={!showPanel}>
                {showPanel && (
                    <div className="absolute left-0 top-full z-50 mt-1 w-96 max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl">
                        <span className="sr-only" role="status">
                            {status}
                        </span>
                        {failed ? (
                            <p className="px-3 py-3 text-sm text-gray-500">Search is unavailable. Press Enter for full results.</p>
                        ) : results.length === 0 ? (
                            <p className="px-3 py-3 text-sm text-gray-500">No results for &ldquo;{asked}&rdquo;</p>
                        ) : (
                            <>
                                <ul className="max-h-96 divide-y divide-gray-50 overflow-auto">
                                    {results.map((result) => (
                                        <li key={result.id}>
                                            <a
                                                href={result.url}
                                                className="flex items-center gap-2.5 px-3 py-2 hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-primary)]"
                                            >
                                                <Icon name={result.icon || 'topic'} className="w-5 h-5 shrink-0 text-gray-400" />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm font-medium text-gray-800">{result.name}</span>
                                                    <span className="block truncate text-xs text-gray-500">
                                                        {result.type}
                                                        {result.code ? ` \u00b7 ${result.code}` : ''}
                                                    </span>
                                                </span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                                <button
                                    type="button"
                                    onClick={submit}
                                    className="block w-full border-t border-gray-100 px-3 py-2 text-center text-xs font-medium text-[var(--color-primary)] hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--color-primary)]"
                                >
                                    All results &rarr;
                                </button>
                            </>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
