import { useRef } from 'react';

const TONE = {
    sev1: 'border-rose-300 bg-rose-50 text-rose-900',
    sev2: 'border-amber-300 bg-amber-50 text-amber-900',
    sev3: 'border-slate-300 bg-slate-50 text-slate-800',
    sev4: 'border-slate-200 bg-slate-50/60 text-slate-600',
};

const TONE_SELECTED = {
    sev1: 'border-rose-500 ring-2 ring-rose-300',
    sev2: 'border-amber-500 ring-2 ring-amber-300',
    sev3: 'border-slate-500 ring-2 ring-slate-300',
    sev4: 'border-slate-400 ring-2 ring-slate-200',
};

/**
 * The four severity band cards, shared between the declaration screen and
 * the crisis room's re-grade modal so the two never diverge
 * (`incident-declaration.md` §2/§7, `crisis-room.md` §2 item 2).
 *
 * A CARD IS NEVER COLOUR ALONE (spec §5) — band name, trigger sentence and,
 * when suggested, the word "Suggested" are all visible text. Overriding the
 * suggested band requires a reason, min length 10, matching the server rule
 * in both `DeclareBcmsIncidentRequest` and `RegradeBcmsIncidentRequest`.
 */
export default function SeverityMatrix({
    bands = [], suggested = null, value, onChange, reason, onReasonChange, reasonError, legendId,
    disabled = false,
}) {
    const groupRef = useRef(null);

    const onKeyDown = (e) => {
        const order = bands.map((b) => b.value);
        const idx = order.indexOf(value);
        let next = null;
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = order[Math.max(0, idx - 1)];
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = order[Math.min(order.length - 1, idx + 1)];
        if (next != null) {
            e.preventDefault();
            onChange(next);
            groupRef.current?.querySelector(`[data-severity="${next}"]`)?.focus();
        }
    };

    const isOverride = suggested != null && value !== suggested;

    return (
        <div>
            <div
                role="radiogroup"
                aria-labelledby={legendId}
                ref={groupRef}
                onKeyDown={onKeyDown}
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                {bands.map((b) => {
                    const selected = value === b.value;
                    const isSuggested = suggested === b.value;
                    return (
                        <button
                            key={b.value}
                            type="button"
                            role="radio"
                            aria-checked={selected}
                            data-severity={b.value}
                            tabIndex={selected ? 0 : -1}
                            disabled={disabled}
                            onClick={() => onChange(b.value)}
                            className={`rounded-lg border p-3 text-left text-sm transition ${TONE[b.value] ?? TONE.sev3} ${
                                selected ? TONE_SELECTED[b.value] ?? '' : ''
                            }`}
                        >
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-semibold">{b.label}</span>
                                {isSuggested && (
                                    <span className="rounded bg-white/70 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide">
                                        Suggested
                                    </span>
                                )}
                            </div>
                            <p className="mt-1 text-xs leading-snug">{b.trigger}</p>
                            {b.is_default && (
                                <p className="mt-1 text-[10px] italic opacity-70">
                                    default — your organisation has not customised this
                                </p>
                            )}
                        </button>
                    );
                })}
            </div>

            {/* Announces which band matched, once, without re-announcing every keystroke (spec §5). */}
            <p aria-live="polite" className="sr-only">
                {suggested ? `Suggested: ${bands.find((b) => b.value === suggested)?.label ?? suggested}` : ''}
            </p>

            {isOverride && (
                <div className="mt-3">
                    <label htmlFor="severity_override_reason" className="block text-xs font-medium text-slate-700">
                        Why does this differ from the suggested severity?
                    </label>
                    <input
                        id="severity_override_reason"
                        type="text"
                        required
                        minLength={10}
                        className="form-input mt-1 w-full text-sm"
                        value={reason ?? ''}
                        onChange={(e) => onReasonChange(e.target.value)}
                    />
                    {reasonError && <p role="alert" className="mt-1 text-xs text-rose-700">{reasonError}</p>}
                </div>
            )}
        </div>
    );
}
