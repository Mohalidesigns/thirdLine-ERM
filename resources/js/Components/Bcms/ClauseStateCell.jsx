/**
 * The four-tone clause state — green / amber / red / grey — used on the
 * compliance matrix and reused wherever a screen needs to describe the same
 * fact (the evidence-pack preview's mandatory-record counts).
 *
 * ICON + TEXT, NEVER COLOUR ALONE (WCAG 1.4.1) — every tone pairs a glyph
 * with a word, so a colour-blind reader and a screen reader receive the same
 * information a sighted reader gets from the tint.
 */
const TONE = {
    green: { icon: '✓', label: 'Evidenced', bg: 'bg-emerald-50', text: 'text-emerald-900' },
    amber: { icon: '◑', label: 'Partial', bg: 'bg-amber-50', text: 'text-amber-900' },
    red: { icon: '✗', label: 'Gap', bg: 'bg-red-50', text: 'text-red-900' },
    grey: { icon: '—', label: 'Not applicable', bg: 'bg-gray-100', text: 'text-gray-700' },
};

export default function ClauseStateCell({ state, artefact, compact = false }) {
    const tone = TONE[state] ?? TONE.grey;

    return (
        <span className={`inline-flex items-start gap-1.5 rounded px-2 py-1 ${tone.bg} ${tone.text}`}>
            <span aria-hidden="true">{tone.icon}</span>
            <span className={compact ? 'text-xs font-medium' : 'text-xs'}>
                {compact ? tone.label : artefact}
            </span>
        </span>
    );
}

export { TONE };
