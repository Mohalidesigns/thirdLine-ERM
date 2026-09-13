import { Head, Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import InputError from '@thirdline/ui/Components/InputError';
import KpiCard from '@thirdline/ui/Components/KpiCard';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import { humanise } from './TestForm';

const shortDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const fileSize = (bytes) => {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

function Panel({ title, actions, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 mb-6">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                {actions}
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

/** Migration Phase 3.4: risk/controls/tests/show.blade.php. */
export default function Show({ test, control, linkedRisks = [], evidence = [], can = {} }) {
    const [rejecting, setRejecting] = useState(false);
    const fileInput = useRef(null);

    const startForm = useForm({});
    const resubmitForm = useForm({});
    const completeForm = useForm({ result: '', findings: '', recommendations: '', score: '' });
    const reviewForm = useForm({ action: 'approve', reviewer_notes: '', rejection_reason: '' });
    // forceFormData: the payload carries a File, which cannot ride Inertia's
    // default JSON encoding.
    const evidenceForm = useForm({ file: null, description: '' });

    const controlUrl = tryRoute('risk.controls.show', control.id);

    const submitComplete = (e) => {
        e.preventDefault();
        completeForm.post(route('risk.control-tests.complete', test.id));
    };

    const submitReview = (e, action) => {
        e.preventDefault();
        reviewForm.transform((data) => ({ ...data, action }));
        reviewForm.post(route('risk.control-tests.review', test.id), {
            onSuccess: () => setRejecting(false),
        });
    };

    const submitEvidence = (e) => {
        e.preventDefault();
        evidenceForm.post(route('risk.control-tests.upload-evidence', test.id), {
            forceFormData: true,
            onSuccess: () => {
                evidenceForm.reset();
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };

    return (
        <AuthenticatedLayout title={test.test_code}>
            <Head title={`${test.test_code} - Control Test`} />

            <PageHeader
                title={test.test_code}
                subtitle={test.title}
                breadcrumbs={[
                    { label: 'Control Testing', href: route('risk.control-tests.dashboard') },
                    { label: test.test_code },
                ]}
                actions={
                    <>
                        {can.update && (
                            <Link href={route('risk.control-tests.edit', test.id)} className="btn-secondary text-sm inline-flex items-center gap-2">
                                <span className="material-symbols-outlined text-lg">edit</span> Edit
                            </Link>
                        )}
                        <Link href={route('risk.control-tests.index')} className="btn-secondary text-sm">Back</Link>
                    </>
                }
            />

            {/* ---- Lifecycle ---- */}
            {can.start && (
                <div className="mb-6 bg-white rounded-xl border border-gray-200 p-5 flex items-center justify-between gap-4">
                    <p className="text-sm text-gray-600">
                        This test is scheduled for {shortDate(test.scheduled_date)}. Starting it records the date and
                        opens the result form.
                    </p>
                    <button
                        type="button"
                        onClick={() => startForm.post(route('risk.control-tests.start', test.id))}
                        disabled={startForm.processing}
                        className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50"
                    >
                        <span className="material-symbols-outlined text-lg">play_arrow</span> Start test
                    </button>
                </div>
            )}

            {can.complete && (
                <Panel title="Record the result">
                    <form onSubmit={submitComplete} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Result <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={completeForm.data.result}
                                    onChange={(e) => completeForm.setData('result', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white"
                                >
                                    <option value="">Select a result</option>
                                    {['effective', 'partially_effective', 'ineffective'].map((result) => (
                                        <option key={result} value={result}>
                                            {humanise(result)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={completeForm.errors.result} className="mt-1" />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">Score (0-100)</label>
                                <input
                                    type="number" min="0" max="100"
                                    value={completeForm.data.score}
                                    onChange={(e) => completeForm.setData('score', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                />
                                <InputError message={completeForm.errors.score} className="mt-1" />
                            </div>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">Findings</label>
                            <textarea
                                rows={3}
                                value={completeForm.data.findings}
                                onChange={(e) => completeForm.setData('findings', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">Recommendations</label>
                            <textarea
                                rows={3}
                                value={completeForm.data.recommendations}
                                onChange={(e) => completeForm.setData('recommendations', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <p className="text-xs text-gray-500">
                                {test.reviewer
                                    ? `Submitting sends this to ${test.reviewer} for review.`
                                    : 'No reviewer is assigned, so this test completes on submission.'}
                            </p>
                            <button type="submit" disabled={completeForm.processing} className="btn-primary text-sm inline-flex items-center gap-2 disabled:opacity-50">
                                <span className="material-symbols-outlined text-lg">send</span> Submit result
                            </button>
                        </div>
                    </form>
                </Panel>
            )}

            {can.review && (
                <div className="mb-6 bg-white rounded-xl border border-blue-200 shadow-sm p-5">
                    <div className="flex items-center gap-2 mb-3">
                        <span className="material-symbols-outlined text-blue-600">rate_review</span>
                        <h3 className="text-sm font-semibold text-gray-900">Review Required</h3>
                    </div>

                    {rejecting ? (
                        <form onSubmit={(e) => submitReview(e, 'reject')} className="space-y-3">
                            <textarea
                                rows={3}
                                value={reviewForm.data.rejection_reason}
                                onChange={(e) => reviewForm.setData('rejection_reason', e.target.value)}
                                placeholder="Why is this being sent back?"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                            <InputError message={reviewForm.errors.rejection_reason} />
                            <div className="flex gap-2">
                                <button type="submit" disabled={reviewForm.processing} className="btn-primary text-sm bg-red-600 disabled:opacity-50">
                                    Confirm rejection
                                </button>
                                <button type="button" onClick={() => setRejecting(false)} className="btn-secondary text-sm">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    ) : (
                        <form onSubmit={(e) => submitReview(e, 'approve')} className="space-y-3">
                            <textarea
                                rows={2}
                                value={reviewForm.data.reviewer_notes}
                                onChange={(e) => reviewForm.setData('reviewer_notes', e.target.value)}
                                placeholder="Reviewer notes (optional)"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                            />
                            <InputError message={reviewForm.errors.reviewer_notes} />
                            <div className="flex gap-2">
                                <button type="submit" disabled={reviewForm.processing} className="btn-primary text-sm disabled:opacity-50">
                                    Approve — the control&rsquo;s rating is recalculated
                                </button>
                                <button type="button" onClick={() => setRejecting(true)} className="btn-secondary text-sm text-red-600">
                                    Reject
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            )}

            {test.status === 'rejected' && (
                <div className="mb-6 bg-red-50 border border-red-200 rounded-xl p-5">
                    <p className="text-sm font-semibold text-red-800">This test was sent back for rework.</p>
                    {test.reviewer_notes && <p className="text-sm text-red-700 mt-1">{test.reviewer_notes}</p>}
                    {can.resubmit && (
                        <button
                            type="button"
                            onClick={() => resubmitForm.post(route('risk.control-tests.resubmit', test.id))}
                            disabled={resubmitForm.processing}
                            className="btn-secondary text-sm mt-3 disabled:opacity-50"
                        >
                            Return to in-progress and rework
                        </button>
                    )}
                </div>
            )}

            {/* ---- KPI row ---- */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <KpiCard
                    icon="fact_check"
                    title="Result"
                    value={humanise(test.result)}
                    subtitle={test.completed_date ? `Completed ${shortDate(test.completed_date)}` : null}
                    unavailable={!test.result}
                    unavailableLabel="Not yet recorded"
                />
                <KpiCard
                    icon="percent"
                    title="Score"
                    value={`${test.score}%`}
                    unavailable={test.score === null || test.score === undefined}
                    unavailableLabel="Not scored"
                />
                <KpiCard icon="event" title="Scheduled" value={shortDate(test.scheduled_date)} subtitle={humanise(test.test_type)} />
                <KpiCard
                    icon="attach_file"
                    title="Evidence"
                    value={evidence.length}
                    subtitle={evidence.length === 0 ? 'Nothing attached' : 'File(s) attached'}
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <div className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Test Detail</h3>
                    {test.description && <p className="text-sm text-gray-700 whitespace-pre-line mb-5">{test.description}</p>}

                    {test.findings && (
                        <div className="mb-4">
                            <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Findings</p>
                            <p className="text-sm text-gray-700 whitespace-pre-line mt-1">{test.findings}</p>
                        </div>
                    )}
                    {test.recommendations && (
                        <div className="mb-4">
                            <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Recommendations</p>
                            <p className="text-sm text-gray-700 whitespace-pre-line mt-1">{test.recommendations}</p>
                        </div>
                    )}
                    {test.reviewer_notes && test.status !== 'rejected' && (
                        <div>
                            <p className="text-xs text-gray-500 font-medium uppercase tracking-wide">Reviewer notes</p>
                            <p className="text-sm text-gray-700 whitespace-pre-line mt-1">{test.reviewer_notes}</p>
                        </div>
                    )}
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-5">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Context</h3>
                    <dl className="space-y-3 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-500">Control</dt>
                            <dd className="text-right font-medium">
                                {controlUrl ? (
                                    <a href={controlUrl} className="text-[#1A365D] underline">
                                        {control.control_code}
                                    </a>
                                ) : (
                                    control.control_code
                                )}
                            </dd>
                        </div>
                        {[
                            ['Type', humanise(test.test_type)],
                            ['Tester', test.tester ?? '-'],
                            ['Reviewer', test.reviewer ?? 'None assigned'],
                            ['Scheduled by', test.creator ?? '-'],
                            ['Started', shortDate(test.started_date)],
                        ].map(([label, value]) => (
                            <div key={label} className="flex justify-between gap-4">
                                <dt className="text-gray-500">{label}</dt>
                                <dd className="text-gray-800 font-medium text-right">{value}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between gap-4">
                            <dt className="text-gray-500">Status</dt>
                            <dd><StatusBadge status={test.status} /></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <Panel title="Risks This Control Mitigates">
                {linkedRisks.length === 0 ? (
                    <p className="text-sm text-gray-500">The control under test is not mapped to any risk.</p>
                ) : (
                    <ul className="space-y-2">
                        {linkedRisks.map((risk) => (
                            <li key={risk.id} className="text-sm">
                                <span className="font-medium text-[#1A365D]">{risk.risk_code}</span> — {risk.title}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title="Evidence">
                {can.uploadEvidence && (
                    <form onSubmit={submitEvidence} className="mb-5 p-4 border border-gray-200 rounded-lg bg-gray-50 space-y-3">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-semibold text-gray-600 mb-1">
                                    File <span className="text-red-500">*</span>
                                </label>
                                <input
                                    ref={fileInput}
                                    type="file"
                                    onChange={(e) => evidenceForm.setData('file', e.target.files?.[0] ?? null)}
                                    className="w-full text-sm"
                                />
                                <InputError message={evidenceForm.errors.file} className="mt-1" />
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-gray-600 mb-1">Description</label>
                                <input
                                    type="text" maxLength={500}
                                    value={evidenceForm.data.description}
                                    onChange={(e) => evidenceForm.setData('description', e.target.value)}
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                                />
                                <InputError message={evidenceForm.errors.description} className="mt-1" />
                            </div>
                        </div>
                        <div className="flex items-center justify-between">
                            <p className="text-xs text-gray-500">
                                Evidence is stored on the private disk and is only ever served through this page.
                            </p>
                            <button
                                type="submit"
                                disabled={evidenceForm.processing || !evidenceForm.data.file}
                                className="btn-primary text-xs inline-flex items-center gap-1 disabled:opacity-50"
                            >
                                <span className="material-symbols-outlined text-sm">upload</span> Upload
                            </button>
                        </div>
                    </form>
                )}

                {evidence.length === 0 ? (
                    <EmptyState icon="attach_file" title="No evidence has been attached to this test." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table w-full">
                            <thead>
                                <tr>
                                    <th>File</th>
                                    <th>Type</th>
                                    <th>Size</th>
                                    <th>Description</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {evidence.map((item) => (
                                    <tr key={item.id}>
                                        <td className="text-sm font-medium text-[#1A365D]">{item.file_name}</td>
                                        <td className="text-sm">{item.file_type ?? '-'}</td>
                                        <td className="text-sm">{fileSize(item.file_size)}</td>
                                        <td className="text-sm text-gray-500">{item.description ?? '-'}</td>
                                        <td className="text-right">
                                            {/* A plain link, not an Inertia one: the response is
                                                a file stream, not a page. */}
                                            <a
                                                href={route('risk.control-tests.download-evidence', [test.id, item.id])}
                                                className="text-xs text-[#1A365D] underline"
                                            >
                                                Download
                                            </a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </AuthenticatedLayout>
    );
}
