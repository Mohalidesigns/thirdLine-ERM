/**
 * The browser's copy of the KRI band rule (ADR 0026 section 6.1, A1.7).
 *
 * A DISPLAY MIRROR. The server recomputes on every write, through
 * App\Support\Kri\KriBandRule; this file only lets a screen show a band while
 * somebody is typing. It is pinned to the server by
 * resources/js/lib/kri-band-truth-table.json, which tools/kri-band-check.mjs
 * runs through THIS file and tests/Unit/Kri/KriBandRuleTest runs through PHP.
 *
 * THE EDGES, FROZEN: a value exactly on the RED boundary is red in both
 * directions; a value exactly on the GREEN boundary is amber in both.
 *
 * Two spellings of direction exist and are never mixed up:
 *   read props / the engine:   higher_worse | lower_worse
 *   the Create/Edit form:      higher_is_worse | lower_is_worse
 * Everything here accepts both and returns the engine's.
 */

export const HIGHER = 'higher_worse';
export const LOWER = 'lower_worse';

export function normaliseDirection(direction) {
    return String(direction ?? '').toLowerCase().startsWith('lower') ? LOWER : HIGHER;
}

/** A boundary as the engine reads it: '' and null are "not set"; 0 is a value. */
function num(value) {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);

    return Number.isFinite(n) ? n : null;
}

/**
 * Line-for-line port of MeasureThreshold::bandFor(): walk the bands in the order
 * given and return the first that admits the value.
 *
 * @returns {object|null} the matching band, or null
 */
export function matchBand(rule, value) {
    const v = num(value);

    if (v === null || !rule || !Array.isArray(rule.bands)) return null;

    const lowerWorse = normaliseDirection(rule.direction) === LOWER;

    for (const band of rule.bands) {
        const min = num(band.min);
        const max = num(band.max);

        if (min !== null && (lowerWorse ? v <= min : v < min)) continue;
        if (max !== null && (lowerWorse ? v > max : v >= max)) continue;

        return band;
    }

    return null;
}

/**
 * 'green' | 'amber' | 'red' | null. Null for an empty or non-numeric value and
 * for a rule with no bands - never 'green'.
 */
export function bandFor(rule, value) {
    const code = matchBand(rule, value)?.code ?? null;

    return code === 'yellow' ? 'amber' : code;
}

export function isRed(rule, value) {
    return bandFor(rule, value) === 'red';
}

const BANDS = {
    green: { code: 'green', label: 'Within appetite', color: '#16A34A' },
    amber: { code: 'amber', label: 'Approaching limit', color: '#F59E0B' },
    red: { code: 'red', label: 'Breach', color: '#DC2626' },
};

/**
 * Port of KriBandRule::forBoundaries() + KriMeasureMigrator::bandsFor(): the rule
 * an unsaved pair of boundaries would produce. Takes either direction spelling,
 * returns the engine's. Fewer than two usable bands means no rule at all.
 */
export function ruleFromBoundaries(direction, green, red) {
    const g = num(green);
    const r = num(red);
    const dir = normaliseDirection(direction);

    let bands;

    if (dir === HIGHER) {
        bands = [
            { ...BANDS.green, min: null, max: g },
            { ...BANDS.amber, min: g, max: r },
            { ...BANDS.red, min: r, max: null },
        ];
    } else {
        bands = [
            { ...BANDS.red, min: null, max: r },
            { ...BANDS.amber, min: r, max: g },
            { ...BANDS.green, min: g, max: null },
        ];
    }

    // A band with neither bound claims everything; drop it, and drop the set if
    // nothing usable survives.
    bands = bands.filter((band) => band.min !== null || band.max !== null);

    return { direction: dir, bands: bands.length > 1 ? bands : [] };
}

/** True when the rule can band a value at all. */
export function hasBands(rule) {
    return Boolean(rule && Array.isArray(rule.bands) && rule.bands.length > 0);
}

/* ------------------------------------------------------------------ */
/*  Wording - ONE helper for the legend, the chips and the sentences    */
/* ------------------------------------------------------------------ */

export const BAND_NAMES = { green: 'Green', amber: 'Amber', red: 'Red' };

/** The order a person reads bands in, whatever order the engine walks them. */
const DISPLAY_ORDER = ['green', 'yellow', 'amber', 'red'];

/**
 * What a band admits, in words, matching the edges above.
 *
 *   higher_worse  admits  min <= v < max      "5 up to but not including 10"
 *   lower_worse   admits  min <  v <= max     "above 5 up to and including 10"
 *
 * @param {(n:number)=>string} fmt formats a boundary, e.g. withUnit(n, unit)
 */
export function describeBand(direction, band, fmt = (n) => String(n)) {
    const min = num(band.min);
    const max = num(band.max);

    if (normaliseDirection(direction) === HIGHER) {
        if (min !== null && max !== null) return `${fmt(min)} up to but not including ${fmt(max)}`;
        if (min !== null) return `${fmt(min)} and above`;
        if (max !== null) return `below ${fmt(max)}`;
    } else {
        if (min !== null && max !== null) return `above ${fmt(min)} up to and including ${fmt(max)}`;
        if (min !== null) return `above ${fmt(min)}`;
        if (max !== null) return `${fmt(max)} and below`;
    }

    return 'any value';
}

/**
 * Legend rows (green, amber, red) generated from a rule.
 *
 * @returns {{code:string, name:string, text:string}[]}
 */
export function legendRows(rule, fmt) {
    if (!hasBands(rule)) return [];

    return [...rule.bands]
        .sort((a, b) => DISPLAY_ORDER.indexOf(a.code) - DISPLAY_ORDER.indexOf(b.code))
        .map((band) => ({
            code: band.code === 'yellow' ? 'amber' : band.code,
            name: BAND_NAMES[band.code === 'yellow' ? 'amber' : band.code] ?? band.code,
            text: describeBand(rule.direction, band, fmt),
        }));
}

/**
 * Do the two boundaries contradict the direction (amber would be empty)?
 * Only meaningful when both are set.
 */
export function boundariesInverted(direction, green, red) {
    const g = num(green);
    const r = num(red);

    if (g === null || r === null) return false;

    return normaliseDirection(direction) === HIGHER ? g > r : g < r;
}

/** Decimal places of a typed number string, for the "at most 6" check. */
export function decimalPlaces(text) {
    const m = /^-?\d*(?:\.(\d+))?$/.exec(String(text ?? '').trim());

    return m && m[1] ? m[1].length : 0;
}
