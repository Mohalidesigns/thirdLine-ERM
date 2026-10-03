import { useEffect, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import tryRoute from '@thirdline/ui/lib/tryRoute';

const FRAMEWORK_LABELS = {
    iso22301: 'ISO 22301 — full clause bundle',
    cbn_csf: 'CBN Risk-Based Cybersecurity Framework',
    cbn_open_banking: 'CBN Open Banking Operational Guidelines',
    dora: 'DORA benchmark crosswalk',
};

/**
 * The regulator evidence pack — `docs/bcms/screens/evidence-pack-export.md`.
 *
 * GENERATION IS SYNCHRONOUS ONLY, PER THE BACKEND'S OWN DECISION
 * (`EvidencePackController`'s docblock). The screen spec's queued path is a
 * named, deliberate deferral, not something this screen pretends to offer —
 * there is no `useJobProgress` here because there is no job to poll.
 *
 * A NATIVE FORM POST, NOT AN INERTIA VISIT, for the same reason
 * `RcsaExports/Index.jsx` uses one: the response is a PDF, and Inertia
 * cannot consume a binary response.
 */
export default function EvidencePack({ frameworks = [], log = [] }) {
    const { flash } = usePage().props;
    const [framework, setFramework] = useState(frameworks[0] ?? 'iso22301');
    const [from, setFrom] = useState(`${new Date().getFullYear()}-01-01`);
    const [to, setTo] = useState(`${new Date().getFullYear()}-12-31`);
    const [preview, setPreview] = useState(null);
    const [checking, setChecking] = useState(false);
    const [generating, setGenerating] = useState(false);

    const [csatFile, setCsatFile] = useState(null);
    const [csatBusy, setCsatBusy] = useState(false);
    const [csatResult, setCsatResult] = useState(null);
    const [csatError, setCsatError] = useState(null);

    useEffect(() => {
        setChecking(true);
        const timer = setTimeout(() => {
            window.axios.get(tryRoute('bcms.reports.regulatory-evidence.preview'), { params: { framework } })
                .then((res) => setPreview(res.data))
                .catch(() => setPreview(null))
                .finally(() => setChecking(false));
        }, 300);

        return () => clearTimeout(timer);
    }, [framework]);

    const generate = (e) => {
        e.preventDefault();
        setGenerating(true);

        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const el = document.createElement('form');
        el.method = 'POST';
        el.action = tryRoute('bcms.reports.regulatory-evidence.store');
        el.style.display = 'none';

        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            el.appendChild(input);
        };

        add('_token', token);
        add('framework', framework);
        add('from', from);
        add('to', to);

        document.body.appendChild(el);
        el.submit();
        document.body.removeChild(el);

        setTimeout(() => setGenerating(false), 2000);
    };

    const submitCsat = (e) => {
        e.preventDefault();
        if (!csatFile) return;

        setCsatBusy(true);
        setCsatError(null);

        const data = new FormData();
        data.append('workbook', csatFile);

        window.axios.post(tryRoute('bcms.reports.csat-prefill'), data, { responseType: 'blob' })
            .then((res) => {
                const matched = res.headers['x-csat-matched'];
                const unmatched = res.headers['x-csat-unmatched'];
                const url = window.URL.createObjectURL(res.data);
                setCsatResult({ matched, unmatched, url });
            })
            .catch(() => setCsatError('The workbook could not be processed. Confirm it is a valid .xlsx file.'))
            .finally(() => setCsatBusy(false));
    };

    return (
        <AppLayout title="Regulatory evidence packs">
            <Head title="Regulatory evidence packs" />

            <PageHeader
                title="Regulatory evidence packs"
                subtitle="One-click, period-scoped bundles — ISO 22301, CBN and DORA. Every export is logged."
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{flash.success}</div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{flash.error}</div>
            )}

            <form onSubmit={generate} className="card mb-8">
                <div className="card-body space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <FormField label="Framework">
                            <select className="form-select" value={framework} onChange={(e) => setFramework(e.target.value)}>
                                {frameworks.map((f) => <option key={f} value={f}>{FRAMEWORK_LABELS[f] ?? f}</option>)}
                            </select>
                        </FormField>
                        <FormField label="From">
                            <input type="date" className="form-input" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </FormField>
                        <FormField label="To">
                            <input type="date" className="form-input" value={to} onChange={(e) => setTo(e.target.value)} />
                        </FormField>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4">
                        <button type="submit" className="btn-primary text-sm" disabled={generating}>
                            {generating ? 'Generating…' : 'Generate'}
                        </button>
                        <span className="text-sm text-gray-600">
                            {checking && 'Checking sections…'}
                            {!checking && preview && (
                                <>
                                    {preview.section_count} section{preview.section_count === 1 ? '' : 's'} —{' '}
                                    {preview.mandatory_green} green, {preview.mandatory_amber} amber, {preview.mandatory_red} red
                                    {' '}(of {preview.mandatory_count} mandatory records). Generates immediately.
                                </>
                            )}
                        </span>
                    </div>

                    {!checking && preview && preview.section_count === 0 && (
                        <p className="text-sm text-amber-700">
                            Every clause in this framework is marked not applicable. The pack will say so, not appear empty.
                        </p>
                    )}
                </div>
            </form>

            <h2 className="mb-2 text-sm font-semibold text-gray-700">Export log</h2>
            <p className="mb-3 text-xs text-gray-500">
                This log is built from the audit trail, not a separate report register — every export leaves this
                trace whether or not the file itself is still downloadable.
            </p>

            <div className="card mb-8">
                <div className="overflow-x-auto">
                    <table className="data-table">
                        <caption className="sr-only">Evidence pack export log</caption>
                        <thead>
                            <tr>
                                <th scope="col">Framework</th>
                                <th scope="col">Period</th>
                                <th scope="col">Requested by</th>
                                <th scope="col">Requested at</th>
                                <th scope="col">Sections</th>
                            </tr>
                        </thead>
                        <tbody>
                            {log.length === 0 && (
                                <tr><td colSpan={5} className="py-12 text-center text-sm text-gray-500">Nothing exported yet.</td></tr>
                            )}
                            {log.map((row, i) => (
                                <tr key={i}>
                                    <td className="text-sm text-gray-800">{FRAMEWORK_LABELS[row.framework] ?? row.framework}</td>
                                    <td className="text-xs text-gray-600">{row.period?.from} – {row.period?.to}</td>
                                    <td className="text-sm text-gray-700">{row.requested_by}</td>
                                    <td className="text-xs text-gray-500">{row.requested_at}</td>
                                    <td className="text-xs">
                                        <span className="text-emerald-700">{row.section_summary?.green ?? 0} green</span>{' '}
                                        <span className="text-amber-700">{row.section_summary?.amber ?? 0} amber</span>{' '}
                                        <span className="text-red-700">{row.section_summary?.red ?? 0} red</span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="card">
                <div className="card-body">
                    <h2 className="text-sm font-semibold text-gray-900">CBN CSAT pre-fill</h2>
                    <p className="mt-1 text-xs text-gray-500">
                        Matches your uploaded workbook by question label and fills the adjacent cell. No cell
                        coordinate is ever invented — every unmatched question is listed on a cover sheet.
                    </p>
                    <p className="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-800">
                        Uploaded files are not scanned for malware in this build — a deliberate, documented gap. Only
                        upload a workbook from a source you trust.
                    </p>

                    <form onSubmit={submitCsat} className="mt-4 flex flex-wrap items-center gap-3">
                        <input type="file" accept=".xlsx" aria-label="Upload this year's CBN CSAT workbook"
                            onChange={(e) => setCsatFile(e.target.files?.[0] ?? null)} />
                        <button type="submit" className="btn-secondary text-sm" disabled={!csatFile || csatBusy}>
                            {csatBusy ? 'Matching questions…' : 'Fill and return'}
                        </button>
                    </form>

                    {csatError && <p className="mt-3 text-sm text-red-700">{csatError}</p>}

                    {csatResult && (
                        <p className="mt-3 text-sm text-gray-700">
                            {csatResult.matched} question(s) matched and filled by label, {csatResult.unmatched} left
                            untouched. See the cover sheet for the unmatched questions.{' '}
                            <a href={csatResult.url} download="csat-prefilled.xlsx" className="underline">Download</a>
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
