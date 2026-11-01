/**
 * A decorative Material Symbols glyph in a fixed, clipped box.
 *
 * The icon font is a network dependency: with it blocked, the ligature name
 * renders as TEXT ("check_circle"). The box is fixed-size and overflow-hidden
 * so a failed font load cannot reflow the layout (00-common section 5), and
 * the glyph is aria-hidden - meaning is always also in words.
 */
export default function Glyph({ name, className = '' }) {
    return (
        <span
            aria-hidden="true"
            className={`material-symbols-outlined inline-block h-5 w-5 shrink-0 overflow-hidden align-middle text-base leading-5 ${className}`}
        >
            {name}
        </span>
    );
}
