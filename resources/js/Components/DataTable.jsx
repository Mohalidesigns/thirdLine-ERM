import { useState } from 'react';

export default function DataTable({ columns, data, emptyMessage = 'No records found', emptyAction, striped = false }) {
    const [sortField, setSortField] = useState(null);
    const [sortDir, setSortDir] = useState('asc');

    const handleSort = (field) => {
        if (!field) return;
        if (sortField === field) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDir('asc');
        }
    };

    const sortedData = sortField
        ? [...data].sort((a, b) => {
            const aVal = typeof columns.find(c => c.field === sortField)?.accessor === 'function'
                ? columns.find(c => c.field === sortField).accessor(a)
                : a[sortField];
            const bVal = typeof columns.find(c => c.field === sortField)?.accessor === 'function'
                ? columns.find(c => c.field === sortField).accessor(b)
                : b[sortField];
            if (aVal == null) return 1;
            if (bVal == null) return -1;
            const cmp = String(aVal).localeCompare(String(bVal), undefined, { numeric: true });
            return sortDir === 'asc' ? cmp : -cmp;
        })
        : data;

    return (
        <div className="overflow-x-auto">
            <table className="data-table">
                <thead>
                    <tr>
                        {columns.map((col, idx) => (
                            <th
                                key={idx}
                                className={col.sortable ? 'cursor-pointer select-none hover:text-gray-700' : ''}
                                onClick={() => col.sortable && handleSort(col.field)}
                                style={col.width ? { width: col.width } : {}}
                            >
                                <span className="inline-flex items-center gap-1">
                                    {col.label}
                                    {col.sortable && sortField === col.field && (
                                        <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round"
                                                d={sortDir === 'asc' ? 'M4.5 15.75l7.5-7.5 7.5 7.5' : 'M19.5 8.25l-7.5 7.5-7.5-7.5'} />
                                        </svg>
                                    )}
                                </span>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {sortedData.length > 0 ? sortedData.map((row, rowIdx) => (
                        <tr key={row.id || rowIdx} className={striped && rowIdx % 2 === 1 ? 'bg-gray-50/50' : ''}>
                            {columns.map((col, colIdx) => (
                                <td key={colIdx}>
                                    {col.render ? col.render(row) : (col.accessor ? col.accessor(row) : row[col.field])}
                                </td>
                            ))}
                        </tr>
                    )) : (
                        <tr>
                            <td colSpan={columns.length} className="text-center py-8">
                                <div className="text-gray-400">
                                    <svg className="w-10 h-10 mx-auto mb-2 opacity-50" fill="none" viewBox="0 0 24 24" strokeWidth={1} stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                    </svg>
                                    <p className="text-sm">{emptyMessage}</p>
                                    {emptyAction && <div className="mt-2">{emptyAction}</div>}
                                </div>
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
