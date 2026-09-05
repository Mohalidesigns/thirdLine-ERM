import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import tryRoute from '@/lib/tryRoute';

/**
 * Stage a spreadsheet for bulk import (migration Phase 5.5).
 *
 * The upload is a real multipart post through Inertia's file handling. The file
 * lands on the PRIVATE `local` disk: WP-11 found it being written to
 * `storage/app/public/imports/{organizationId}/`, which is symlinked to
 * `public/storage`, so a bulk risk register — every risk, owner and rating in
 * one file — was served straight off the web server at a URL whose only
 * variable was the organisation id.
 */
export default function Create({ types, accepts }) {
    const { data, setData, post, processing, errors, progress } = useForm({
        import_type: 'risks',
        file: null,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('risk.imports.upload'), { forceFormData: true });
    };

    const indexUrl = tryRoute('risk.imports.index');

    return (
        <AuthenticatedLayout title="New Import">
            <Head title="New Import" />

            <PageHeader
                title="Import Data"
                subtitle="Bring a spreadsheet of records into the register"
                actions={
                    indexUrl && (
                        <Link href={indexUrl} className="btn-secondary text-sm">
                            Import history
                        </Link>
                    )
                }
            />

            <form onSubmit={submit} className="space-y-6 max-w-2xl">
                <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
                    <div>
                        <InputLabel htmlFor="import_type" value="What are you importing?" />
                        <select
                            id="import_type"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.import_type}
                            onChange={(e) => setData('import_type', e.target.value)}
                            required
                        >
                            {Object.entries(types).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                        <InputError message={errors.import_type} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="file" value="Spreadsheet" />
                        <input
                            id="file"
                            type="file"
                            accept=".csv,.xlsx,.xls"
                            onChange={(e) => setData('file', e.target.files[0])}
                            className="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-[#1A365D] file:text-white hover:file:bg-[#2D4A7A]"
                            required
                        />
                        <p className="text-xs text-gray-500 mt-2">
                            CSV, XLSX or XLS. The first row is read as the column headers; you choose what each one
                            maps to on the next screen.
                        </p>
                        <InputError message={errors.file} className="mt-1" />
                    </div>

                    {progress && (
                        <div className="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
                            <div className="h-2 bg-[#1A365D] transition-all" style={{ width: `${progress.percentage}%` }} />
                        </div>
                    )}
                </section>

                <div className="flex items-center justify-end gap-3">
                    {indexUrl && (
                        <Link href={indexUrl}>
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                    )}
                    <PrimaryButton disabled={processing || !data.file}>Upload and map columns</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
