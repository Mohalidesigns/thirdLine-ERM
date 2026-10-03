import { Fragment, useState } from 'react';

/**
 * The section table both regulatory packs render.
 *
 * THE COVERAGE COLUMN IS THE POINT. Anyone can download a workbook; what a
 * preparer needs before filing is which sections have gaps and why. The
 * preview is behind a click and capped at ten rows, because a pack with
 * thousands of sub-processor disclosures is not shipped to a browser so
 * somebody can check the shape of a table.
 */
export default function PackSections({ sections = [], exportUrl, canExport = false }) {
    const [open, setOpen] = useState(sections[0]?.code ?? null);

    return (
        <div className="card overflow-hidden">
            <table className="data-table">
                <thead className="bg-gray-50">
                    <tr>
                        <th scope="col" className="text-left">Section</th>
                        <th scope="col" className="text-left">Title</th>
                        <th scope="col" className="text-left">Authority</th>
                        <th scope="col" className="text-right">Rows</th>
                        <th scope="col" className="text-left">Coverage</th>
                        <th scope="col" />
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                    {sections.map((section) => (
                        <Fragment key={section.code}>
                            <tr>
                                <td className="font-medium">
                                    <button
                                        type="button"
                                        className="text-indigo-600"
                                        onClick={() => setOpen(open === section.code ? null : section.code)}
                                    >
                                        {section.code}
                                    </button>
                                </td>
                                <td>
                                    {section.title}
                                    {section.note && (
                                        <div className="form-hint">{section.note}</div>
                                    )}
                                </td>
                                <td className="text-xs text-gray-600">{section.citation}</td>
                                <td className="text-right tabular-nums">{section.row_count}</td>
                                <td>
                                    <span
                                        className={`rounded px-2 py-0.5 text-xs font-medium ${
                                            section.coverage === 'complete'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-amber-50 text-amber-700'
                                        }`}
                                    >
                                        {section.coverage === 'complete' ? 'Complete' : 'Gaps'}
                                    </span>
                                </td>
                                <td className="text-right">
                                    {canExport && (
                                        <a className="text-xs text-indigo-600" href={exportUrl('csv', section.code)}>
                                            CSV
                                        </a>
                                    )}
                                </td>
                            </tr>
                            {open === section.code && (
                                <tr>
                                    <td colSpan={6} className="bg-gray-50">
                                        {section.row_count === 0 ? (
                                            <p className="text-sm text-gray-600">
                                                No rows. Read that against the note before treating it as a
                                                statement that there is nothing to declare.
                                            </p>
                                        ) : (
                                            <div className="overflow-x-auto">
                                                <p className="mb-2 text-xs text-gray-500">
                                                    First {Math.min(10, section.row_count)} of {section.row_count} rows.
                                                    The export holds all of them.
                                                </p>
                                                <table className="data-table text-xs">
                                                    <thead>
                                                        <tr>
                                                            {section.headers.map((header) => (
                                                                <th
                                                                    key={header}
                                                                    scope="col"
                                                                    className="whitespace-nowrap text-left"
                                                                >
                                                                    {header}
                                                                </th>
                                                            ))}
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {section.preview.map((row, index) => (
                                                            <tr key={index}>
                                                                {row.map((value, cell) => (
                                                                    <td key={cell} className="whitespace-nowrap">
                                                                        {value === null || value === '' ? '—' : String(value)}
                                                                    </td>
                                                                ))}
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            )}
                        </Fragment>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
