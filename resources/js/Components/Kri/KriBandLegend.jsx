import Glyph from './Glyph';
import { boundariesInverted, legendRows, ruleFromBoundaries } from '@/lib/kri-band';
import { withUnit } from '@/lib/units';

const TONES = {
    green: 'bg-green-100 text-green-800',
    amber: 'bg-yellow-100 text-yellow-800',
    red: 'bg-red-100 text-red-800',
};

/**
 * The band legend, generated from the rule (corrected from BandPreview, ADR
 * 0026 section 6.1): on the red boundary is red in both directions, on the green
 * boundary is amber in both. Shared by the KRI form and Complete my KRIs so the
 * two surfaces cannot disagree - the exact failure the ADR records.
 *
 * Accepts either direction spelling.
 */
export default function KriBandLegend({ direction, green, red, unit, rule = null, variant = 'stacked', emptyText = 'Enter both boundaries to see the bands.' }) {
    const set = (v) => v !== '' && v !== null && v !== undefined && Number.isFinite(Number(v));

    // A server rule (bandRule) is the engine's own; boundaries are what a form is typing.
    if (!rule && (!set(green) || !set(red))) {
        return <p className="text-xs text-gray-600">{emptyText}</p>;
    }

    const rows = legendRows(rule ?? ruleFromBoundaries(direction, green, red), (n) => withUnit(n, unit));
    const inverted = !rule && boundariesInverted(direction, green, red);
    const layout = variant === 'inline' ? 'flex flex-wrap gap-x-5 gap-y-2' : 'space-y-2';

    return (
        <div>
            <ul className={layout}>
                {rows.map((row) => (
                    <li key={row.code} className="flex items-center gap-2 text-xs">
                        <span className={`inline-flex w-16 justify-center rounded-full px-2.5 py-0.5 font-medium ${TONES[row.code] ?? 'bg-gray-100 text-gray-700'}`}>{row.name}</span>
                        <span className="text-gray-700">{row.text}</span>
                    </li>
                ))}
            </ul>
            {inverted && (
                <p className="mt-2 flex items-start gap-1 text-xs text-amber-800">
                    <Glyph name="warning" className="!h-4 !w-4" />
                    <span>The green and red boundaries look the wrong way round for this direction. Amber would be empty.</span>
                </p>
            )}
        </div>
    );
}
