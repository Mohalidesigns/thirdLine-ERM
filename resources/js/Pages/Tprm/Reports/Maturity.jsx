import { Head, Link, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * Programme maturity — FR-RPT-06.
 *
 * THE COVERAGE FRACTION IS ON EVERY ROW. "14 of 18 categories scored" says
 * something about the assessment as well as the programme, and an assessment
 * that only scored the categories it was proud of should be visible as such.
 *
 * THE TREND PLOTS APPROVED ASSESSMENTS ONLY, and each period carries the
 * framework version its scores were recorded under — a level 3 under a revised
 * rubric is not the same claim as a level 3 under the old one.
 *
 * THE TWO LENSES ARE NEVER AVERAGED TOGETHER. VRMMM levels describe programme
 * maturity and CSF subcategories describe outcomes; one number over both would
 * mean nothing.
 */
export default function Maturity({ assessments = [], trend = {}, levels = {}, can = {} }) {
    const { data, setData, post, processing, errors } = useForm({
        period_label: '',
        as_at: new Date().toISOString().slice(0, 10),
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tprm.reports.maturity.open'));
    };

    const periods = trend.periods ?? [];
    const means = trend.means ?? {};

    return (
        <AppLayout title="Programme maturity">
            <Head title="Programme maturity" />

            <PageHeader
                title="Third-party programme maturity"
                subtitle="Eight VRMMM categories and the ten NIST CSF 2.0 GV.SC subcategories, scored 0 to 5. Approving an assessment freezes its scores onto the trend."
            />

            <div className="mb-4 rounded border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600">
                <strong>About the rubric.</strong> The eight VRMMM category names are used as published; the
                level criteria shipped against them are this product&rsquo;s own, because Shared Assessments&rsquo;
                criteria are licensed content. The NIST CSF 2.0 subcategory titles are quoted from the framework,
                which is in the public domain, and are read from the shipped control library rather than
                restated. Each assessment records the rubric version its scores were made under.
            </div>

            {can.assess && (
                <form onSubmit={submit} className="card mb-6 flex flex-wrap items-end gap-4 p-4">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Period</span>
                        <input
                            type="text"
                            className="w-40 rounded border-gray-300 text-sm"
                            placeholder="H1 2027"
                            value={data.period_label}
                            onChange={(event) => setData('period_label', event.target.value)}
                        />
                        {errors.period_label && (
                            <span className="mt-1 block text-xs text-red-600">{errors.period_label}</span>
                        )}
                    </label>

                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Position as at</span>
                        <input
                            type="date"
                            className="rounded border-gray-300 text-sm"
                            value={data.as_at}
                            onChange={(event) => setData('as_at', event.target.value)}
                        />
                        {errors.as_at && <span className="mt-1 block text-xs text-red-600">{errors.as_at}</span>}
                    </label>

                    <button type="submit" className="btn-primary" disabled={processing}>
                        Open an assessment
                    </button>

                    <p className="w-full text-xs text-gray-500">
                        Every category is created unscored. A form with eighteen rows, four still reading
                        &ldquo;Not assessed&rdquo; at approval, says something the assessment should say.
                    </p>
                </form>
            )}

            {periods.length > 1 && (
                <div className="card mb-6 overflow-x-auto p-4">
                    <h2 className="mb-2 text-sm font-semibold text-gray-800">Mean level across approved periods</h2>
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th scope="col" className="px-3 py-1 text-left font-medium text-gray-600">Lens</th>
                                {periods.map((period) => (
                                    <th key={period.period_label} scope="col" className="px-3 py-1 text-right font-medium text-gray-600">
                                        {period.period_label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {['vrmmm', 'nist_csf'].map((framework) => (
                                <tr key={framework}>
                                    <td className="px-3 py-1">
                                        {framework === 'vrmmm' ? 'VRMMM categories' : 'NIST CSF GV.SC'}
                                    </td>
                                    {(means[framework] ?? []).map((point) => (
                                        <td key={point.period_label} className="px-3 py-1 text-right tabular-nums">
                                            {point.mean === null ? (
                                                <span className="text-gray-400" title="Nothing scored in this lens">—</span>
                                            ) : (
                                                <>
                                                    {point.mean}
                                                    <span className="ml-1 text-xs text-gray-400">({point.scored})</span>
                                                </>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-2 text-xs text-gray-500">
                        The bracketed figure is how many categories carried a score. The two lenses are shown
                        separately because averaging programme maturity with control outcomes would produce a
                        number that means nothing.
                    </p>
                </div>
            )}

            <div className="card overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Period</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">As at</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Coverage</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Rubric</th>
                            <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Approved by</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {assessments.map((assessment) => (
                            <tr key={assessment.uuid}>
                                <td className="px-4 py-2 font-medium">
                                    <Link className="text-indigo-600" href={assessment.url}>
                                        {assessment.period_label}
                                    </Link>
                                </td>
                                <td className="px-4 py-2">{assessment.as_at}</td>
                                <td className="px-4 py-2">
                                    {assessment.scored} of {assessment.categories} categories scored
                                </td>
                                <td className="px-4 py-2">
                                    <span
                                        className={`rounded px-2 py-0.5 text-xs font-medium ${
                                            assessment.status === 'approved'
                                                ? 'bg-emerald-50 text-emerald-700'
                                                : 'bg-gray-100 text-gray-700'
                                        }`}
                                    >
                                        {assessment.status === 'approved' ? 'Approved — frozen' : 'Draft'}
                                    </span>
                                </td>
                                <td className="px-4 py-2 text-xs text-gray-600">{assessment.framework_version}</td>
                                <td className="px-4 py-2">{assessment.approved_by ?? '—'}</td>
                            </tr>
                        ))}
                        {assessments.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-500">
                                    No maturity assessment has been made. The first one is the baseline every
                                    later period is measured against.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 text-xs text-gray-500">
                <strong>Levels:</strong>{' '}
                {Object.entries(levels).map(([level, label]) => `${level} ${label}`).join(' · ')}. A category
                nobody has looked at reads &ldquo;Not assessed&rdquo;, which is not the same as level 0.
            </div>
        </AppLayout>
    );
}
