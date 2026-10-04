/**
 * A three-option Yes / No / Unknown radiogroup — the two reportability
 * questions, on the declaration screen and the crisis room's re-prompt
 * banner alike (`incident-declaration.md` §3, `crisis-room.md` §2 item 3).
 *
 * "UNKNOWN" IS A REAL, LABELLED THIRD OPTION, not a greyed-out default a
 * screen reader would skip past (spec §5) — each `<label>` wraps its own
 * input with distinct visible text, so the accessible name is correct without
 * an explicit `aria-label` repeating it.
 */
export default function ReportabilityQuestion({ name, legend, hint, value, onChange, disabled = false }) {
    const options = [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
        { value: 'unknown', label: 'Unknown' },
    ];

    return (
        <fieldset disabled={disabled}>
            <legend className="text-sm font-medium text-slate-800">{legend}</legend>
            {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
            <div className="mt-2 flex gap-2">
                {options.map((o) => (
                    <label
                        key={o.value}
                        className={`flex-1 cursor-pointer rounded border px-3 py-2 text-center text-sm ${
                            value === o.value ? 'border-slate-700 bg-slate-800 text-white' : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={o.value}
                            checked={value === o.value}
                            onChange={() => onChange(o.value)}
                            className="sr-only"
                        />
                        {o.label}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}
