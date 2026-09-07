/**
 * A risk tier or residual band, rendered so it never depends on hue alone.
 *
 * TRD §11 requires the fixed, colour-blind-safe palette (Low #1B7F5A,
 * Moderate #B8860B, High #C05621, Critical #9B1C1C) AND "a shape or letter
 * token alongside the colour so the band never depends on hue alone". That
 * second half is the accessible part and the one usually dropped: roughly one
 * man in twelve cannot reliably separate the Moderate amber from the High
 * orange, and a tier is the single most consequential value on the screen.
 *
 * So every badge carries its initial in a filled token — L, M, H, C — which is
 * legible in greyscale, survives a photocopied board pack, and reads correctly
 * to a screen reader through the accompanying label.
 */

const TIERS = {
    low: { label: 'Low', token: 'L', bg: '#E7F3EE', fg: '#1B7F5A', dot: '#1B7F5A' },
    moderate: { label: 'Moderate', token: 'M', bg: '#FBF2DF', fg: '#8A6508', dot: '#B8860B' },
    high: { label: 'High', token: 'H', bg: '#FCEDE4', fg: '#9C4318', dot: '#C05621' },
    critical: { label: 'Critical', token: 'C', bg: '#FBE9E9', fg: '#9B1C1C', dot: '#9B1C1C' },
};

const UNKNOWN = { label: 'Not tiered', token: '–', bg: '#F3F4F6', fg: '#4B5563', dot: '#9CA3AF' };

export default function TierBadge({ tier, label, size = 'md', showLabel = true }) {
    const config = TIERS[tier] ?? UNKNOWN;
    const text = label ?? config.label;
    const small = size === 'sm';

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full font-medium ${
                small ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-sm'
            }`}
            style={{ backgroundColor: config.bg, color: config.fg }}
        >
            <span
                aria-hidden="true"
                className={`inline-flex items-center justify-center rounded-full font-bold text-white ${
                    small ? 'h-4 w-4 text-[10px]' : 'h-5 w-5 text-[11px]'
                }`}
                style={{ backgroundColor: config.dot }}
            >
                {config.token}
            </span>
            {showLabel && <span>{text}</span>}
            {!showLabel && <span className="sr-only">{text}</span>}
        </span>
    );
}

export { TIERS };
