import { useEffect, useRef, useState } from 'react';

/** A small click-outside dropdown used by the grid toolbar and row menus. */
export default function Menu({ button, children, align = 'right', width = 'w-56', className = '' }) {
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        if (!open) return undefined;
        const onClick = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <div ref={ref} className={`relative ${className}`}>
            {button({ open, toggle: () => setOpen((o) => !o) })}
            {open && (
                <div className={`absolute ${align === 'right' ? 'right-0' : 'left-0'} top-full z-20 mt-1 ${width} rounded-xl border border-gray-200 bg-white p-2 shadow-xl`}>
                    {typeof children === 'function' ? children({ close: () => setOpen(false) }) : children}
                </div>
            )}
        </div>
    );
}
