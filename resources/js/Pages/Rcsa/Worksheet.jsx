import { useRef } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import RcsaWorksheetTable, { EMPTY_LINE } from './RcsaWorksheetTable';

/** Migration Phase 3.8: risk/rcsa/worksheet.blade.php. */
function Section({ index, title, children }) {
    return (
        <section className="card mb-6">
            <div className="card-header flex items-center gap-2">
                <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">
                    {index}
                </div>
                <h2 className="form-section-title text-base">{title}</h2>
            </div>
            <div className="card-body space-y-4">{children}</div>
        </section>
    );
}

export default function Worksheet({
    canSubmit = false,
    businessUnits = [],
    processes = [],
    categories = [],
    campaigns = [],
    assessableRisks = [],
    mySubmissions = [],
}) {
    const { data, setData, post, processing, errors, transform } = useForm({
        campaign_id: '',
        business_unit_id: '',
        process_id: '',
        assessment_date: new Date().toISOString().slice(0, 10),
        risks: [{ ...EMPTY_LINE }],
    });

    // Which button was pressed. A ref rather than form state because setData
    // is asynchronous: setting `action` and posting in the same handler would
    // race, and a Save Draft that posts action:'submit' files the worksheet.
    const action = useRef('submit');

    // Empty selects post as '' and the validator wants null or an id.
    transform((form) => ({
        ...form,
        action: action.current,
        campaign_id: form.campaign_id || null,
        process_id: form.process_id || null,
        risks: form.risks.map((line) => ({ ...line, risk_id: line.risk_id || null })),
    }));

    const changeLine = (index, key, value) =>
        setData('risks', data.risks.map((line, i) => (i === index ? { ...line, [key]: value } : line)));

    const removeLine = (index) => setData('risks', data.risks.filter((_, i) => i !== index));

    const addLine = () => setData('risks', [...data.risks, { ...EMPTY_LINE }]);

    const submit = (intent) => (e) => {
        e.preventDefault();
        action.current = intent;
        post(route('risk.rcsa.worksheet.store'));
    };

    return (
        <AuthenticatedLayout title="RCSA Worksheet">
            <Head title="RCSA Worksheet" />

            <PageHeader
                title="Risk &amp; Control Self-Assessment Worksheet"
                subtitle="Assess risks and controls for your business unit processes"
                breadcrumbs={[{ label: 'RCSA', href: route('risk.rcsa.dashboard') }, { label: 'Worksheet' }]}
            />

            {mySubmissions.length > 0 && (
                <div className="card mb-6">
                    <div className="card-header flex items-center justify-between">
                        <h2 className="form-section-title">Your recent worksheets</h2>
                        <span className="text-xs text-gray-400">Filed as campaign assignments</span>
                    </div>
                    <div className="card-body">
                    <ul className="divide-y divide-gray-100">
                        {mySubmissions.map((submission) => (
                            <li key={submission.id} className="flex items-center justify-between gap-4 py-2.5">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-800 truncate">
                                        {submission.unit ?? 'Unassigned unit'}
                                        <span className="text-gray-400 font-normal"> · </span>
                                        <span className="text-gray-500 font-normal">{submission.campaignCode}</span>
                                    </p>
                                    <p className="text-xs text-gray-500 mt-0.5">
                                        {submission.lines} risk {submission.lines === 1 ? 'line' : 'lines'}
                                        {submission.submittedAt && ` · ${submission.submittedAt}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3 flex-shrink-0">
                                    <StatusBadge status={submission.status} />
                                    {submission.url && (
                                        <Link href={submission.url} className="text-xs text-[#1A365D] font-medium hover:underline">
                                            View
                                        </Link>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    </div>
                </div>
            )}

            <form onSubmit={submit('submit')}>
                <Section index={1} title="Assessment Context">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label className="form-label">Assessment Campaign</label>
                            <select aria-label="Assessment Campaign"
                                value={data.campaign_id}
                                onChange={(e) => setData('campaign_id', e.target.value)}
                                className="form-select"
                            >
                                <option value="">Current open RCSA campaign</option>
                                {campaigns.map((campaign) => (
                                    <option key={campaign.id} value={campaign.id}>{campaign.code} — {campaign.title}</option>
                                ))}
                            </select>
                            <p className="text-xs text-gray-500 mt-1">
                                Leave as-is to file against the open RCSA campaign. If none is open, one is created for
                                you — your worksheet is never discarded.
                            </p>
                            <InputError message={errors.campaign_id} className="mt-1" />
                        </div>
                        <div>
                            <label className="form-label">
                                Assessment Date <span className="form-required" aria-hidden="true">*</span>
                            </label>
                            <input aria-label="Assessment Date"
                                type="date"
                                value={data.assessment_date}
                                onChange={(e) => setData('assessment_date', e.target.value)}
                                className="form-input"
                            />
                            <InputError message={errors.assessment_date} className="mt-1" />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <label className="form-label">
                                Business Unit <span className="form-required" aria-hidden="true">*</span>
                            </label>
                            <select aria-label="Business Unit"
                                value={data.business_unit_id}
                                onChange={(e) => setData('business_unit_id', e.target.value)}
                                className="form-select"
                            >
                                <option value="">Select Unit</option>
                                {businessUnits.map((unit) => (
                                    <option key={unit.id} value={unit.id}>{unit.name}</option>
                                ))}
                            </select>
                            <InputError message={errors.business_unit_id} className="mt-1" />
                        </div>
                        <div>
                            {/* Optional, and labelled so. The Blade form marked
                                this required in the markup while the server has
                                always accepted it as nullable. */}
                            <label className="form-label">Process</label>
                            <select aria-label="Process"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value)}
                                className="form-select"
                            >
                                <option value="">Select Process</option>
                                {processes.map((process) => (
                                    <option key={process.id} value={process.id}>{process.name}</option>
                                ))}
                            </select>
                            <InputError message={errors.process_id} className="mt-1" />
                        </div>
                    </div>
                </Section>

                <Section index={2} title="Risk Assessment">
                    <RcsaWorksheetTable
                        lines={data.risks}
                        categories={categories}
                        assessableRisks={assessableRisks}
                        onChange={changeLine}
                        onRemove={removeLine}
                        onAdd={addLine}
                        errors={errors}
                    />
                </Section>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.rcsa.dashboard')} className="btn-secondary text-sm">Cancel</Link>
                    {canSubmit && (
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={submit('draft')}
                                disabled={processing}
                                className="btn-secondary text-sm disabled:opacity-50"
                            >
                                Save Draft
                            </button>
                            <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                                <span className="material-symbols-outlined text-lg">send</span> Submit Assessment
                            </button>
                        </div>
                    )}
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
