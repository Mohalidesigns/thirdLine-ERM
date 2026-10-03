import { useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import ClauseStateCell from '@/Components/Bcms/ClauseStateCell';

/**
 * The clause-by-clause evidence matrix — `docs/bcms/screens/
 * compliance-evidence-matrix.md`.
 *
 * A PURE READ SURFACE. Nothing here writes to a clause, an obligation or an
 * artefact — every mutation this screen can trigger (raising a finding from
 * the gap analyser) posts to a route that already exists elsewhere and is
 * owned by that screen.
 *
 * FOUR STATES, NEVER THREE, AND NEVER A BARE COLOUR. 9.2 programme/results
 * never render grey (ADR 0021 §1) — the service enforces that, this screen
 * only renders what it is given.
 */
export default function Matrix({
    empty_programme: emptyProgramme,
    programme,
    newer_draft_programme: newerDraftProgramme,
    register_seeded: registerSeeded,
    sections = {},
    summary = {},
    kris = [],
    maturity,
    can = {},
}) {
    const { flash } = usePage().props;
    const [openStandards, setOpenStandards] = useState(() => {
        const params = new URLSearchParams(window.location.search);
        const opened = params.get('open');

        return opened ? opened.split(',') : ['ISO 22301:2019'];
    });
    const [drawerRow, setDrawerRow] = useState(null);
    const [aiOpen, setAiOpen] = useState(false);
    const [aiLoading, setAiLoading] = useState(false);
    const [aiError, setAiError] = useState(null);
    const [aiFindings, setAiFindings] = useState(null);

    const toggleStandard = (standard) => {
        setOpenStandards((prev) => {
            const next = prev.includes(standard) ? prev.filter((s) => s !== standard) : [...prev, standard];
            const params = new URLSearchParams(window.location.search);

            if (next.length > 0) params.set('open', next.join(','));
            else params.delete('open');

            window.history.replaceState({}, '', `${window.location.pathname}?${params.toString()}`);

            return next;
        });
    };

    const notLinkedKriCount = useMemo(() => kris.filter((k) => !k.linked).length, [kris]);

    if (emptyProgramme) {
        return (
            <AppLayout title="Compliance & evidence">
                <Head title="Compliance & evidence" />
                <PageHeader title="Compliance & evidence" subtitle="No business continuity programme exists yet." />
                <p className="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                    Every clause needs a programme to be scoped against.{' '}
                    <Link href={tryRoute('bcms.programme.index')} className="underline">Create one in Programme governance</Link>.
                </p>
            </AppLayout>
        );
    }

    const runGapAnalysis = () => {
        setAiLoading(true);
        setAiError(null);
        window.axios.post(tryRoute('bcms.reports.gap-analysis.ai'))
            .then((res) => setAiFindings(res.data.findings ?? []))
            .catch(() => setAiError('Gap analysis is unavailable right now.'))
            .finally(() => setAiLoading(false));
    };

    const raise = (finding) => {
        router.post(tryRoute('bcms.reports.gap-analysis.raise'), {
            finding: finding.description,
            clause_ref: finding.iso_clause_ref,
        }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setAiFindings((prev) => prev.filter((f) => f !== finding)),
        });
    };

    const dismiss = (finding) => setAiFindings((prev) => prev.filter((f) => f !== finding));

    const tiles = [
        { label: 'Green clauses', value: summary.green, tone: 'bg-white border-gray-200' },
        { label: 'Amber clauses', value: summary.amber, tone: 'bg-white border-gray-200' },
        { label: 'Red clauses', value: summary.red, tone: 'bg-white border-gray-200' },
        { label: 'Grey (not applicable)', value: summary.grey, tone: 'bg-white border-gray-200' },
        {
            label: 'Mandatory records not fully evidenced',
            value: summary.mandatory_gap_count,
            tone: summary.mandatory_gap_count > 0 ? 'border-red-300 bg-red-50' : 'bg-white border-gray-200',
        },
    ];

    // A programme only moves forward (draft → approved → active); the
    // governing programme picked by `programmeAsOf()` is honestly
    // "approved" only in those two states — the fallback tier hands back
    // whatever programme exists even when nothing has been approved yet,
    // and that must not read as if it governs.
    const governingIsApproved = programme.status === 'approved' || programme.status === 'active';
    const governingStatement = governingIsApproved
        ? `Assessed against the approved ${programme.year} programme.`
        : `No approved programme yet — showing the ${programme.year} draft.`;

    return (
        <AppLayout title="Compliance & evidence">
            <Head title="Compliance & evidence" />

            <PageHeader
                title="Compliance & evidence"
                subtitle={`ISO 22301, ISO 22318 and CBN. ${governingStatement} ${summary.green ?? 0} green · ${summary.amber ?? 0} partial · ${summary.grey ?? 0} not applicable · ${summary.red ?? 0} gaps, ${summary.mandatory_gap_count ?? 0} of them mandatory records not fully evidenced.`}
                actions={can.export && (
                    <Link href={tryRoute('bcms.reports.regulatory-evidence.index')} className="btn-primary text-sm">
                        Export evidence pack
                    </Link>
                )}
            />

            {newerDraftProgramme && (
                <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                    A {newerDraftProgramme.year} programme is in draft. The matrix will use it once it is approved.
                </div>
            )}

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">{flash.success}</div>
            )}

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-5">
                {tiles.map((t) => (
                    <div key={t.label} className={`rounded-lg border p-4 ${t.tone}`}>
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{t.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{t.value ?? 0}</p>
                    </div>
                ))}
            </div>

            {!registerSeeded && (
                <div className="mb-6 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    The obligations register has never been loaded.{' '}
                    <Link href={tryRoute('bcms.programme.index')} className="underline">Load it in Programme governance</Link>{' '}
                    — until then, every clause that could be marked not-applicable instead shows "applicability not yet determined".
                </div>
            )}

            <div className="space-y-4">
                {Object.entries(sections).map(([standard, rows]) => {
                    const open = openStandards.includes(standard);

                    return (
                        <section key={standard} className="rounded-lg border border-gray-200 bg-white">
                            <button
                                type="button"
                                aria-expanded={open}
                                onClick={() => toggleStandard(standard)}
                                className="flex w-full items-center justify-between px-4 py-3 text-left"
                            >
                                <span className="text-sm font-semibold text-gray-900">{standard}</span>
                                <span className="text-xs text-gray-500">
                                    {rows.length} clause{rows.length === 1 ? '' : 's'} {open ? '▲' : '▼'}
                                </span>
                            </button>

                            {open && (
                                <div className="overflow-x-auto border-t border-gray-100">
                                    <table className="data-table">
                                        <caption className="sr-only">{standard} — clause compliance</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">Clause</th>
                                                <th scope="col">Title</th>
                                                <th scope="col">Mandatory?</th>
                                                <th scope="col">State</th>
                                                <th scope="col">Artefact</th>
                                                <th scope="col">Last evidenced</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.map((row) => (
                                                <tr
                                                    key={row.code}
                                                    className="cursor-pointer hover:bg-gray-50"
                                                    onClick={() => setDrawerRow(row)}
                                                >
                                                    <td className="font-mono text-xs text-gray-700">{row.code}</td>
                                                    <td className="text-sm text-gray-800">{row.title}</td>
                                                    <td>
                                                        {row.mandatory && (
                                                            <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${row.state === 'red' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700'}`}>
                                                                Mandatory
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td><ClauseStateCell state={row.state} artefact={row.artefact} /></td>
                                                    <td className="max-w-md text-xs text-gray-600">{row.artefact}</td>
                                                    <td className="whitespace-nowrap text-xs text-gray-500">{row.last_evidenced ?? ''}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    );
                })}
            </div>

            {maturity && (
                <div className="mt-6 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700">
                    Maturity score {maturity.overall_score ?? '—'}/5, assessed {maturity.assessed_at}, method {maturity.method_version} —{' '}
                    <Link href={tryRoute('bcms.programme.index')} className="underline">see Programme governance for the full clause-group breakdown</Link>.
                </div>
            )}

            <div className="mt-6 rounded-lg border border-gray-200 bg-white p-6">
                <h2 className="text-sm font-semibold text-gray-900">Resilience KRIs (clause 9.1)</h2>
                {notLinkedKriCount > 0 && (
                    <p className="mt-1 text-xs text-amber-700">{notLinkedKriCount} of {kris.length} resilience KRIs are not linked.</p>
                )}
                <div className="mt-3 overflow-x-auto rounded border border-gray-200">
                    <table className="data-table">
                        <caption className="sr-only">Resilience KRIs adopted for clause 9.1</caption>
                        <thead>
                            <tr>
                                <th scope="col">Code</th>
                                <th scope="col">Name</th>
                                <th scope="col">Target</th>
                                <th scope="col">Current value</th>
                                <th scope="col">Last measured</th>
                                <th scope="col">Producer</th>
                            </tr>
                        </thead>
                        <tbody>
                            {kris.map((k) => (
                                <tr key={k.kri_code}>
                                    <td className="font-mono text-xs">{k.kri_code}</td>
                                    <td className="text-sm text-gray-800">{k.name}</td>
                                    <td className="text-xs text-gray-600">{k.target} {k.unit}</td>
                                    <td className="text-sm">
                                        {!k.linked ? (
                                            <span className="text-gray-400">Not linked</span>
                                        ) : k.current_value === null ? (
                                            <span className="text-gray-400">No measurement</span>
                                        ) : (
                                            <span className={
                                                k.current_status === 'breach' ? 'text-red-700' : k.current_status === 'warning' ? 'text-amber-700' : 'text-emerald-700'
                                            }>
                                                {k.current_value} {k.unit}
                                            </span>
                                        )}
                                        {k.linked && (
                                            <Link href={tryRoute('risk.kri.show', k.kri_id)} className="ml-2 text-xs text-gray-500 underline">view</Link>
                                        )}
                                    </td>
                                    <td className="text-xs text-gray-500">{k.last_measured_at ?? '—'}</td>
                                    <td className="text-xs text-gray-500">{k.producer}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="mt-6 rounded-lg border border-gray-200 bg-white p-6">
                <button type="button" aria-expanded={aiOpen} onClick={() => setAiOpen((v) => !v)}
                    className="text-sm font-semibold text-gray-900">
                    Ask the gap analyser {aiOpen ? '▲' : '▼'}
                </button>

                {aiOpen && (
                    <div className="mt-3">
                        <p className="text-xs text-gray-500">
                            A templated, computed draft over the same data this matrix already shows — never counted
                            towards a clause's state until you raise it as a finding.
                        </p>
                        <button type="button" className="btn-secondary mt-2 text-sm" disabled={aiLoading} onClick={runGapAnalysis}>
                            {aiLoading ? 'Analysing…' : 'Run gap analysis'}
                        </button>
                        {aiError && <p className="mt-2 text-sm text-gray-500">{aiError}</p>}

                        {aiFindings && (
                            <ul className="mt-4 space-y-3" aria-live="polite">
                                {aiFindings.length === 0 && (
                                    <li className="text-sm text-gray-500">No new gaps found beyond what the matrix already shows.</li>
                                )}
                                {aiFindings.map((f, i) => (
                                    <li key={i} className="rounded border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-sky-700">AI-generated draft</p>
                                        <p className="mt-1">{f.description}</p>
                                        <div className="mt-2 flex gap-3">
                                            <button type="button" className="text-xs font-medium underline" onClick={() => raise(f)}>Raise as a finding</button>
                                            <button type="button" className="text-xs text-gray-500 underline" onClick={() => dismiss(f)}>Dismiss</button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </div>

            {drawerRow && (
                <div role="dialog" aria-modal="true" aria-label={`${drawerRow.code} evidence`}
                    className="fixed inset-0 z-50 flex justify-end bg-gray-900/40">
                    <div className="h-full w-full max-w-md overflow-y-auto bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between">
                            <div>
                                <p className="font-mono text-xs text-gray-500">{drawerRow.code}</p>
                                <h2 className="text-base font-semibold text-gray-900">{drawerRow.title}</h2>
                            </div>
                            <button type="button" className="text-sm text-gray-500 underline" onClick={() => setDrawerRow(null)}>Close</button>
                        </div>
                        <div className="mt-4">
                            <ClauseStateCell state={drawerRow.state} artefact={drawerRow.artefact} />
                        </div>
                        <p className="mt-4 text-sm text-gray-700">{drawerRow.artefact}</p>
                        {drawerRow.last_evidenced && (
                            <p className="mt-2 text-xs text-gray-500">Last evidenced {drawerRow.last_evidenced}</p>
                        )}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
