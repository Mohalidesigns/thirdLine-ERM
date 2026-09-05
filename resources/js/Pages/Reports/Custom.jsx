import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import { LibraryLink } from '@/Components/Reporting/ReportShell';

/**
 * Custom report builder (migration Phase 5.4).
 *
 * The section list, the report types, the ratings and the formats all come
 * from GenerateCustomReportRequest — the class that validates them. The Blade
 * template held its own copies as literals, so what the form offered and what
 * the validator accepted were two independent lists that only happened to
 * agree.
 *
 * The submit posts a normal form (not an Inertia visit) because the response
 * is a FILE: DocumentRenderer streams a PDF, xlsx or CSV back. An Inertia
 * visit expects a page.
 */
export default function Custom({ categories, businessUnits, savedTemplates, sections, reportTypes, ratings, formats, defaults }) {
    const { data, setData, errors } = useForm({
        report_name: '',
        report_type: 'summary',
        date_from: defaults.date_from,
        date_to: defaults.date_to,
        categories: categories.map((c) => c.id),
        ratings: ratings.map((r) => r.toLowerCase()),
        business_units: businessUnits.map((u) => u.id),
        sections: defaults.sections,
        format: 'pdf',
    });

    const toggle = (key, value) => {
        const current = data[key];
        setData(key, current.includes(value) ? current.filter((v) => v !== value) : [...current, value]);
    };

    return (
        <AuthenticatedLayout title="Custom Report Builder">
            <Head title="Custom Report Builder" />

            <PageHeader
                title="Custom Report Builder"
                subtitle="Build a report with the filters, sections and format you choose"
                actions={<LibraryLink />}
            />

            {/*
                A real form post: the response is a streamed document, not a page.
            */}
            <form method="POST" action={route('risk.reports.custom.generate')} className="space-y-6">
                <input type="hidden" name="_token" value={document.querySelector('meta[name="csrf-token"]')?.content ?? ''} />

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 space-y-6">
                        <section className="bg-white rounded-xl border border-gray-200 p-6">
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Report Configuration</h3>

                            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel htmlFor="report_name" value="Report name" />
                                    <TextInput
                                        id="report_name"
                                        name="report_name"
                                        className="mt-1 block w-full"
                                        value={data.report_name}
                                        onChange={(e) => setData('report_name', e.target.value)}
                                        placeholder="e.g. Q4 Risk Summary"
                                        required
                                    />
                                    <InputError message={errors.report_name} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="report_type" value="Report type" />
                                    <select
                                        id="report_type"
                                        name="report_type"
                                        className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={data.report_type}
                                        onChange={(e) => setData('report_type', e.target.value)}
                                    >
                                        {Object.entries(reportTypes).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <InputLabel htmlFor="date_from" value="Date from" />
                                    <TextInput
                                        id="date_from"
                                        name="date_from"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.date_from}
                                        onChange={(e) => setData('date_from', e.target.value)}
                                    />
                                </div>

                                <div>
                                    <InputLabel htmlFor="date_to" value="Date to" />
                                    <TextInput
                                        id="date_to"
                                        name="date_to"
                                        type="date"
                                        className="mt-1 block w-full"
                                        value={data.date_to}
                                        onChange={(e) => setData('date_to', e.target.value)}
                                    />
                                </div>
                            </div>
                        </section>

                        <section className="bg-white rounded-xl border border-gray-200 p-6">
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Filters</h3>

                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <p className="text-xs font-medium text-gray-600 mb-1">Risk categories</p>
                                    {categories.map((category) => (
                                        <label key={category.id} className="flex items-center gap-2 py-1">
                                            <input
                                                type="checkbox"
                                                name="categories[]"
                                                value={category.id}
                                                className="rounded border-gray-300 text-[#1A365D]"
                                                checked={data.categories.includes(category.id)}
                                                onChange={() => toggle('categories', category.id)}
                                            />
                                            <span className="text-xs text-gray-700">{category.name}</span>
                                        </label>
                                    ))}
                                </div>

                                <div>
                                    <p className="text-xs font-medium text-gray-600 mb-1">Risk ratings</p>
                                    {ratings.map((rating) => (
                                        <label key={rating} className="flex items-center gap-2 py-1">
                                            <input
                                                type="checkbox"
                                                name="ratings[]"
                                                value={rating.toLowerCase()}
                                                className="rounded border-gray-300 text-[#1A365D]"
                                                checked={data.ratings.includes(rating.toLowerCase())}
                                                onChange={() => toggle('ratings', rating.toLowerCase())}
                                            />
                                            <span className="text-xs text-gray-700">{rating}</span>
                                        </label>
                                    ))}
                                </div>

                                <div>
                                    <p className="text-xs font-medium text-gray-600 mb-1">Business units</p>
                                    {businessUnits.map((unit) => (
                                        <label key={unit.id} className="flex items-center gap-2 py-1">
                                            <input
                                                type="checkbox"
                                                name="business_units[]"
                                                value={unit.id}
                                                className="rounded border-gray-300 text-[#1A365D]"
                                                checked={data.business_units.includes(unit.id)}
                                                onChange={() => toggle('business_units', unit.id)}
                                            />
                                            <span className="text-xs text-gray-700">{unit.name}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        </section>

                        <section className="bg-white rounded-xl border border-gray-200 p-6">
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Sections</h3>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                {Object.entries(sections).map(([key, label]) => (
                                    <label key={key} className="flex items-center gap-2 p-3 bg-gray-50 rounded-lg hover:bg-blue-50 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="sections[]"
                                            value={key}
                                            className="rounded border-gray-300 text-[#1A365D]"
                                            checked={data.sections.includes(key)}
                                            onChange={() => toggle('sections', key)}
                                        />
                                        <span className="text-xs text-gray-700">{label}</span>
                                    </label>
                                ))}
                            </div>

                            <InputError message={errors.sections} className="mt-2" />
                        </section>
                    </div>

                    <div className="space-y-6">
                        <section className="bg-white rounded-xl border border-gray-200 p-6">
                            <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Format</h3>

                            <div className="space-y-2">
                                {formats.map((format) => (
                                    <label key={format} className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="format"
                                            value={format}
                                            className="text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={data.format === format}
                                            onChange={() => setData('format', format)}
                                        />
                                        <span className="text-sm text-gray-700 uppercase">{format}</span>
                                    </label>
                                ))}
                            </div>

                            <PrimaryButton className="w-full justify-center mt-6">Generate report</PrimaryButton>
                        </section>

                        {savedTemplates.length > 0 && (
                            <section className="bg-white rounded-xl border border-gray-200 p-6">
                                <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Recent Reports</h3>
                                <ul className="divide-y divide-gray-100">
                                    {savedTemplates.map((template) => (
                                        <li key={template.id} className="py-2">
                                            <Link
                                                href={route('risk.reports.status', template.id)}
                                                className="text-sm text-[#1A365D] hover:underline"
                                            >
                                                {template.name}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
