const GLYPHS = {
    effective: ['check_circle', 'text-emerald-600'],
    passed: ['check_circle', 'text-emerald-600'],
    partially_effective: ['error', 'text-amber-500'],
    partial: ['error', 'text-amber-500'],
    ineffective: ['cancel', 'text-red-600'],
    failed: ['cancel', 'text-red-600'],
};

/** measure_table — measure, type, responsible, implemented ✓, status glyph. */
export default function MeasureTable({ data }) {
    const rows = data.rows || [];

    return (
        <div className="h-full overflow-auto">
            <table className="min-w-full divide-y divide-gray-100 text-xs">
                <thead>
                    <tr className="text-left text-[10px] uppercase tracking-wide text-gray-400">
                        <th className="px-2 py-1.5 font-medium">Measure</th>
                        <th className="px-2 py-1.5 font-medium">Type</th>
                        <th className="px-2 py-1.5 font-medium">Responsible</th>
                        <th className="px-2 py-1.5 text-center font-medium">Implemented</th>
                        <th className="px-2 py-1.5 text-center font-medium">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                    {rows.length === 0 && (
                        <tr><td colSpan={5} className="px-2 py-6 text-center text-gray-400">No measures in scope.</td></tr>
                    )}
                    {rows.map((row, i) => {
                        const [icon, tone] = GLYPHS[String(row.glyph || '').toLowerCase()] || ['radio_button_unchecked', 'text-gray-300'];
                        return (
                            <tr key={row.id ?? i} className="hover:bg-gray-50">
                                <td className="max-w-[18rem] truncate px-2 py-1.5 font-medium text-gray-800" title={row.name}>{row.name}</td>
                                <td className="px-2 py-1.5 text-gray-600">{row.type ?? '—'}</td>
                                <td className="px-2 py-1.5 text-gray-600">{row.responsible ?? '—'}</td>
                                <td className="px-2 py-1.5 text-center">
                                    {row.implemented ? (
                                        <span className="material-symbols-outlined text-[18px] leading-none text-emerald-600">check</span>
                                    ) : (
                                        <span className="text-gray-300">—</span>
                                    )}
                                </td>
                                <td className="px-2 py-1.5 text-center">
                                    <span className={`material-symbols-outlined text-[18px] leading-none ${tone}`}>{icon}</span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
