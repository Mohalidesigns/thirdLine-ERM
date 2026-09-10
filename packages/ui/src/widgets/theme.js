/*
| Theme token reader for the widget chart layer.
|
| Every colour a chart uses is a CSS custom property declared in
| resources/css/widgets.css (light values on :root, dark values behind
| prefers-color-scheme + [data-theme] scopes). JS never hardcodes a theme:
| it reads the *computed* value at render time, so rebuilding a chart after
| a scheme change is enough to restyle it. The fallbacks below simply mirror
| widgets.css for the case where the stylesheet has not loaded.
|
| Categorical palette: the dataviz reference palette, validated with
| scripts/validate_palette.js in both modes (adjacent-pair CVD >= 8, normal
| vision >= 15). Slots are assigned in FIXED order, never cycled. RAG /
| band-semantic marks never use these slots — their colours arrive in the
| payload (scoring-profile bands) and are used verbatim.
*/

const FALLBACK = {
    '--viz-surface': '#ffffff',
    '--viz-ink': '#1a202c',
    '--viz-ink-secondary': '#4a5568',
    '--viz-muted': '#718096',
    '--viz-grid': '#e8eaf0',
    '--viz-axis': '#cbd5e0',
    '--viz-tooltip-bg': '#1a202c',
    '--viz-tooltip-ink': '#f7fafc',
    '--viz-trend-up': '#d03b3b',
    '--viz-trend-flat': '#64748b',
    '--viz-trend-down': '#0ca30c',
    '--color-accent': '#d4af37',
    '--viz-series-1': '#2a78d6',
    '--viz-series-2': '#eb6834',
    '--viz-series-3': '#1baf7a',
    '--viz-series-4': '#eda100',
    '--viz-series-5': '#e87ba4',
    '--viz-series-6': '#008300',
    '--viz-series-7': '#4a3aa7',
    '--viz-series-8': '#e34948',
    '--viz-seq-1': '#b7d3f6',
    '--viz-seq-2': '#86b6ef',
    '--viz-seq-3': '#5598e7',
    '--viz-seq-4': '#2a78d6',
    '--viz-seq-5': '#1c5cab',
    '--viz-seq-6': '#0d366b',
};

export function readTokens() {
    const styles = getComputedStyle(document.documentElement);
    const v = (name) => {
        const value = styles.getPropertyValue(name).trim();
        return value || FALLBACK[name] || '#888888';
    };

    return {
        surface: v('--viz-surface'),
        ink: v('--viz-ink'),
        inkSecondary: v('--viz-ink-secondary'),
        muted: v('--viz-muted'),
        grid: v('--viz-grid'),
        axis: v('--viz-axis'),
        tooltipBg: v('--viz-tooltip-bg'),
        tooltipInk: v('--viz-tooltip-ink'),
        trendUp: v('--viz-trend-up'),
        trendFlat: v('--viz-trend-flat'),
        trendDown: v('--viz-trend-down'),
        accent: v('--color-accent'),
        series: [1, 2, 3, 4, 5, 6, 7, 8].map((i) => v(`--viz-series-${i}`)),
        sequential: [1, 2, 3, 4, 5, 6].map((i) => v(`--viz-seq-${i}`)),
        fontFamily: "'Inter', ui-sans-serif, system-ui, sans-serif",
    };
}

/* hex → rgba with alpha; passes non-hex values through untouched. */
export function alpha(color, a) {
    if (typeof color !== 'string' || color[0] !== '#') return color;
    let hex = color.slice(1);
    if (hex.length === 3) hex = hex.replace(/./g, (c) => c + c);
    if (hex.length !== 6) return color;
    const n = parseInt(hex, 16);
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`;
}

/* Relative luminance of a hex colour — used to pick readable text on fills. */
export function isDarkColor(color) {
    if (typeof color !== 'string' || color[0] !== '#') return false;
    let hex = color.slice(1);
    if (hex.length === 3) hex = hex.replace(/./g, (c) => c + c);
    const n = parseInt(hex, 16);
    const lum = 0.2126 * ((n >> 16) & 255) + 0.7152 * ((n >> 8) & 255) + 0.0722 * (n & 255);
    return lum < 145;
}

export const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const plain = new Intl.NumberFormat('en', { maximumFractionDigits: 1 });

export function fmtNum(value) {
    if (value === null || value === undefined || Number.isNaN(+value)) return '–';
    const n = +value;
    return Math.abs(n) >= 10000 ? compact.format(n) : plain.format(n);
}

export function fmtNaira(value) {
    if (value === null || value === undefined || Number.isNaN(+value)) return '–';
    return '₦' + compact.format(+value);
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function fmtDate(date, withYear = false) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
    const base = `${MONTHS[date.getMonth()]} ${date.getDate()}`;
    return withYear ? `${base} '${String(date.getFullYear()).slice(-2)}` : base;
}
