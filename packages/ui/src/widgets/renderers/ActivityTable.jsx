const RAG = { red: 'bg-red-500', amber: 'bg-amber-400', green: 'bg-emerald-500' };

/** activity_table — name, responsible, start, end, progress bar, RAG. */
export default function ActivityTable({ data }) {
    const rows = data.rows || [];

    return (
        <div className="h-full overflow-auto">
            <table className="min-w-full divide-y divide-gray-100 text-xs">
                <thead>
                    <tr className="text-left text-[10px] uppercase tracking-wide text-gray-400">
                        <th className="px-2 py-1.5 font-medium">Activity</th>
                        <th className="px-2 py-1.5 font-medium">Responsible</th>
                        <th className="px-2 py-1.5 font-medium">Start</th>
                        <th className="px-2 py-1.5 font-medium">End</th>
                        <th className="px-2 py-1.5 font-medium">Progress</th>
                        <th className="w-6 px-2 py-1.5" />
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {rows.length === 0 && (
                        <tr><td colSpan={6} className="px-2 py-6 text-center text-gray-400">No activities in scope.</td></tr>
                    )}
                    {rows.map((row, i) => {
                        const pct = Math.max(0, Math.min(100, parseInt(row.progress_pct ?? 0, 10) || 0));
                        const tone = RAG[row.rag] || 'bg-gray-300';
                        return (
                            <tr key={row.id ?? i} className="hover:bg-gray-50">
                                <td className="max-w-[16rem] truncate px-2 py-1.5 font-medium text-gray-800" title={row.name}>{row.name}</td>
                                <td className="px-2 py-1.5 text-gray-600">{row.responsible ?? '—'}</td>
                                <td className="whitespace-nowrap px-2 py-1.5 tabular-nums text-gray-500">{row.start ?? '—'}</td>
                                <td className="whitespace-nowrap px-2 py-1.5 tabular-nums text-gray-500">{row.end ?? '—'}</td>
                                <td className="w-32 px-2 py-1.5">
                                    <div className="flex items-center gap-1.5">
                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100">
                                            <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
                                        </div>
                                        <span className="w-8 text-right tabular-nums text-gray-500">{pct}%</span>
                                    </div>
                                </td>
                                <td className="px-2 py-1.5">
                                    <span className={`inline-block h-2.5 w-2.5 rounded-full ${tone}`} title={`${String(row.rag || '').toUpperCase()} — ${row.status || ''}`} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
