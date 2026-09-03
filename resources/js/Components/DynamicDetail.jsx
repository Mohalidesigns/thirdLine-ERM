/**
 * The read view of the same metadata DynamicForm writes, from the shape
 * App\Presenters\FormSchemaPresenter::detail() produces (migration Phase 2,
 * 2.4). The React counterpart of <x-dynamic-detail>.
 *
 * Values arrive already displayed — an enum as its label, money as
 * "NGN 1,234.00", a bool as Yes/No — because how a stored value reads is a
 * server-side rule shared with the Blade renderer, not something the client
 * should re-derive. A null value is a dash; a PII field is marked, not hidden.
 */
export default function DynamicDetail({ detail }) {
    const sections = detail?.sections ?? [];

    if (sections.length === 0) {
        return (
            <p className="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm text-gray-500">
                Nothing recorded against the configured fields for this record.
            </p>
        );
    }

    return (
        <div className="space-y-6">
            {sections.map((section) => (
                <section key={section.code}>
                    {sections.length > 1 && (
                        <h3 className="mb-3 border-b border-gray-100 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {section.label}
                        </h3>
                    )}

                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                        {section.fields.map((field) => (
                            <div key={field.code} className={field.block ? 'md:col-span-2' : ''}>
                                <dt className="text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                    {field.label}
                                    {field.pii && (
                                        <span
                                            className="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[9px] font-medium normal-case tracking-normal text-amber-700"
                                            title="Personal data — redacted in exports and never logged"
                                        >
                                            PII
                                        </span>
                                    )}
                                </dt>
                                <dd
                                    className={
                                        'mt-1 text-sm ' +
                                        (field.value === null || field.value === undefined ? 'text-gray-300' : 'text-gray-900') +
                                        (field.block ? ' whitespace-pre-line' : '')
                                    }
                                >
                                    {field.value ?? '—'}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
            ))}
        </div>
    );
}
