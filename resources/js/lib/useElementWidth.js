import { useEffect, useState } from "react";

/**
 * The visible width of an element, kept current as it resizes.
 *
 * For a full-width row inside a horizontally scrolling table: a `colSpan` cell
 * is as wide as the whole table, so a form or panel laid out in it puts its
 * right-hand half — the submit button, the Verify button — beyond the edge of
 * the scroller. Pinning that content `sticky left-0` at the scroller's own
 * width keeps all of it on screen however far the table is scrolled
 * (2026-10-21 E2E, at 1024px).
 *
 * `null` until measured, so the first render falls back to the natural width
 * rather than to zero. `watch` re-attaches when the element can mount and
 * unmount — the workspace grid exists only in Grid mode.
 */
export default function useElementWidth(ref, watch = null) {
    const [width, setWidth] = useState(null);

    useEffect(() => {
        const element = ref.current;

        if (!element || typeof ResizeObserver === "undefined") return;

        const observer = new ResizeObserver(() =>
            setWidth(element.clientWidth),
        );

        observer.observe(element);
        setWidth(element.clientWidth);

        return () => observer.disconnect();
    }, [ref, watch]);

    return width;
}
