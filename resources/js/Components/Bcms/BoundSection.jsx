/**
 * One bound section's live data, with its provenance.
 *
 * THE "LAST VERIFIED" STAMP IS NOT DECORATION. A recovery-objectives table with
 * no date beside it is indistinguishable from one somebody typed in 2024, which
 * is the entire problem this module exists to solve. Never verified is said in
 * words, not left blank.
 *
 * COLUMNS ARE DERIVED FROM THE FIRST ROW, so a new source added on the server
 * renders without a matching component having to be remembered. Structured
 * values — a list of process codes, a nested contact block — are rendered
 * rather than coerced, because a cell reading "Array" has appeared in more than
 * one bank's board pack.
 */
export default function BoundSection({ live, section }) {
    if (!live) return null;

    const rows = live.rows ?? [];
    // Primary keys are not shown. "Process id 4" means nothing to somebody
    // reading this during an outage, and a column of database ids is the tell
    // that a report was never read by anybody who had to use it.
    const columns = rows.length > 0
        ? Object.keys(rows[0]).filter((c) => c !== 'id' && !c.endsWith('_id'))
        : [];

    const cell = (value) => {
        if (value === null || value === undefined) return <span className="text-gray-400">—</span>;
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (Array.isArray(value)) {
            return value.length === 0
                ? <span className="text-gray-400">—</span>
                : <span className="text-xs">{value.map((v) => (typeof v === 'object' ? (v.name ?? JSON.stringify(v)) : v)).join(', ')}</span>;
        }
        if (typeof value === 'object') {
            return <span className="text-xs">{value.name ?? value.title ?? JSON.stringify(value)}</span>;
        }

        return String(value);
    };

    return (
        <div className="mt-3">
            <p className="mb-2 text-xs text-gray-500">
                Assembled from {live.label}.{' '}
                {section?.last_verified_at
                    ? <>Last verified {new Date(section.last_verified_at).toLocaleString()}.</>
                    : <span className="font-medium text-amber-700">Never verified against its source.</span>}
                {section?.needs_review && (
                    <span className="ml-2 rounded bg-amber-100 px-2 py-0.5 text-amber-800">Source has changed</span>
                )}
                {section?.is_overridden && (
                    <span className="ml-2 rounded bg-gray-100 px-2 py-0.5 text-gray-700">Edited by hand</span>
                )}
            </p>

            {rows.length === 0 ? (
                <p className="rounded border border-dashed border-gray-300 bg-gray-50 p-4 text-xs text-gray-600">
                    {live.empty_reason ?? 'Nothing to show for this section.'}
                </p>
            ) : (
                <div className="overflow-x-auto rounded border border-gray-200">
                    <table className="min-w-full divide-y divide-gray-200 text-xs">
                        <thead className="bg-gray-50 text-[10px] uppercase tracking-wide text-gray-500">
                            <tr>
                                {columns.map((c) => (
                                    <th key={c} className="px-3 py-2 text-left">{c.replace(/_/g, ' ')}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((row, i) => (
                                <tr key={i}>
                                    {columns.map((c) => (
                                        <td key={c} className="px-3 py-2 align-top">{cell(row[c])}</td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {(live.notes ?? []).map((note, i) => (
                <p key={i} className="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">{note}</p>
            ))}
        </div>
    );
}
