import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

const humanise = (field) =>
    field.replaceAll('_id', '').replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());

/**
 * Say which spreadsheet column feeds which field (migration Phase 5.5).
 *
 * `systemFields` is `DataImport::fieldsFor()` — the same list
 * ProcessImportRequest accepts. Until this phase the screen's copy lived on the
 * controller and the validator had NO rule on the mapping's keys at all, so a
 * caller could name any fillable column on the target model.
 */
export default function Mapping({ import: dataImport, headers, systemFields, typeLabel }) {
    const { data, setData, post, processing, errors } = useForm({
        column_mapping: Object.fromEntries(
            systemFields.map((field) => {
                // Pre-select a header whose name matches the field, which is
                // what a spreadsheet exported from this product will have.
                const index = headers.findIndex(
                    (header) => String(header).toLowerCase().replaceAll(' ', '_') === field,
                );

                return [field, index >= 0 ? index : ''];
            }),
        ),
    });

    const setField = (field, value) =>
        setData('column_mapping', { ...data.column_mapping, [field]: value });

    const submit = (event) => {
        event.preventDefault();

        // Drop the unmapped fields rather than posting empty strings: the
        // processor treats an absent field and an empty one differently.
        const mapping = Object.fromEntries(
            Object.entries(data.column_mapping).filter(([, index]) => index !== '' && index !== null),
        );

        post(route('risk.imports.process', dataImport.id), {
            data: { column_mapping: mapping },
        });
    };

    const mappedCount = Object.values(data.column_mapping).filter((v) => v !== '' && v !== null).length;

    return (
        <AuthenticatedLayout title="Map Columns">
            <Head title="Map Columns" />

            <PageHeader
                title="Map Columns"
                subtitle={`${typeLabel} · ${dataImport.file_name}${dataImport.total_rows ? ` · ${dataImport.total_rows} rows` : ''}`}
            />

            <form onSubmit={submit} className="space-y-6">
                <section className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <header className="px-5 py-4 border-b border-gray-100">
                        <h3 className="text-sm font-semibold text-[#1A365D]">Column mapping</h3>
                        <p className="text-xs text-gray-500 mt-1">
                            Leave a field unmapped to skip it. {mappedCount} of {systemFields.length} mapped.
                        </p>
                    </header>

                    <div className="overflow-x-auto">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Spreadsheet column</th>
                                    <th>First row</th>
                                </tr>
                            </thead>
                            <tbody>
                                {systemFields.map((field) => {
                                    const chosen = data.column_mapping[field];

                                    return (
                                        <tr key={field}>
                                            <td className="font-medium text-[#1A365D] text-sm">{humanise(field)}</td>
                                            <td>
                                                <select
                                                    className="block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                                                    value={chosen}
                                                    onChange={(e) => setField(field, e.target.value === '' ? '' : Number(e.target.value))}
                                                    aria-label={`Column for ${humanise(field)}`}
                                                >
                                                    <option value="">Not imported</option>
                                                    {headers.map((header, index) => (
                                                        <option key={index} value={index}>{header}</option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="text-xs text-gray-500">
                                                {chosen === '' || chosen === null ? '—' : headers[chosen]}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                <InputError message={errors.column_mapping} />
                {Object.entries(errors)
                    .filter(([key]) => key.startsWith('column_mapping.'))
                    .map(([key, message]) => (
                        <InputError key={key} message={message} />
                    ))}

                <div className="flex items-center justify-end gap-3">
                    <Link href={route('risk.imports.index')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={processing || mappedCount === 0}>Start import</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
