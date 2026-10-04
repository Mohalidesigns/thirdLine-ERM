/**
 * A live gate checklist — ✓/✗ with text, never an icon alone
 * (`incident-stand-down.md` §2/§5). A real `<ol>` so a screen reader
 * announces position and count, matching the AAR builder's own pattern.
 */
export default function Checklist({ items = [] }) {
    return (
        <ol className="space-y-2">
            {items.map((item) => (
                <li
                    key={item.key}
                    className={`flex items-start gap-2 rounded border p-3 text-sm ${
                        item.met ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'
                    }`}
                >
                    <span aria-hidden="true" className="mt-0.5 font-semibold">{item.met ? '✓' : '✗'}</span>
                    <span>
                        <span className="font-medium">{item.label}</span>
                        {!item.met && item.message && <span className="block text-xs">{item.message}</span>}
                    </span>
                </li>
            ))}
        </ol>
    );
}
