import Glyph from './Glyph';
import { BAND_BADGE, RETURN_STATUS, plural } from '@/lib/kri-format';

/** A return's derived status, always icon plus words (colour is never the only signal). */
export function ReturnStatusBadge({ status, daysOverdue = null }) {
    const s = RETURN_STATUS[status] ?? RETURN_STATUS.not_started;
    const withDays = status === 'overdue' && daysOverdue !== null && daysOverdue !== undefined;

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${s.tone}`}>
            <Glyph name={s.glyph} className="!h-4 !w-4 text-sm leading-4" />
            {s.label}
            {withDays && <span>{`· ${daysOverdue} ${plural(daysOverdue, 'day')}`}</span>}
        </span>
    );
}

/** Green / Amber / Red / No band. A null band is never shown as green. */
export function BandBadge({ band, className = '' }) {
    const key = band === 'yellow' ? 'amber' : band;
    const b = BAND_BADGE[key] ?? BAND_BADGE.none;

    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${b.tone} ${className}`}>
            <Glyph name={b.glyph} className="!h-4 !w-4 text-sm leading-4" />
            {b.label}
        </span>
    );
}

/** The "Draft" / "Submitted" tag beside a value: a draft is not a reading. */
export function ValueTag({ submitted }) {
    return (
        <span
            className="ml-2 inline-block rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 text-xs font-medium text-gray-700"
            title={submitted ? 'Submitted' : 'Not yet submitted. A draft is not a reading.'}
        >
            {submitted ? 'Submitted' : 'Draft'}
            {!submitted && <span className="sr-only"> (not yet submitted; a draft is not a reading)</span>}
        </span>
    );
}
