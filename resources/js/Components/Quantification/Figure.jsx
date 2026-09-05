import { isMissing, NOT_RECORDED } from './figures';

/**
 * One figure in a report table, or the explicit absence in its place.
 *
 * Callers pass the ALREADY-FORMATTED string (from figures.js) plus the raw
 * value, so the absence is decided by the value and never by an empty string.
 */
export default function Figure({ value, formatted, absent = NOT_RECORDED, className = '' }) {
    if (isMissing(value)) {
        return <span className={`text-gray-400 italic ${className}`}>{absent}</span>;
    }

    return <span className={className}>{formatted}</span>;
}
