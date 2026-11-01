import { useEffect, useState } from 'react';

/** True while the media query matches. Used where rendering BOTH a table and a card list would duplicate controls and ids. */
export default function useMediaQuery(query, initial = true) {
    const [matches, setMatches] = useState(() => (typeof window === 'undefined' || !window.matchMedia ? initial : window.matchMedia(query).matches));

    useEffect(() => {
        if (!window.matchMedia) return undefined;

        const mq = window.matchMedia(query);
        const on = () => setMatches(mq.matches);

        on();
        mq.addEventListener('change', on);

        return () => mq.removeEventListener('change', on);
    }, [query]);

    return matches;
}
