import { useState } from 'react';
import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import StatusBadge from '@thirdline/ui/Components/StatusBadge';
import ScorePanel from '@/Components/Tprm/ScorePanel';
import TierBadge from '@/Components/Tprm/TierBadge';

/**
 * The Engagement Workspace — "the most important screen" (TRD §11).
 *
 * Phase 1 builds the tabs that have something behind them: Summary, Inherent
 * Risk and the score history. The rest — due diligence, assessments, contracts,
 * obligations, findings, monitoring, exit — are listed and marked as arriving
 * with their phase.
 *
 * THEY ARE MARKED "ARRIVES IN PHASE N" RATHER THAN SHOWN EMPTY. An empty
 * Findings tab says this engagement has no findings; a tab that says the
 * module is not built yet says something entirely different, and only one of
 * them is true.
 */
const PENDING_TABS = [
    ['Due Diligence', 'Phase 6'],
    ['Assessments', 'Phase 2'],
    ['Contracts & Clauses', 'Phase 4'],
    ['Obligations & SLAs', 'Phase 4'],
    ['Connections & Access', 'Phase 7'],
    ['Sub-processors', 'Phase 7'],
    ['Findings', 'Phase 5'],
    ['Monitoring', 'Phase 6'],
    ['Exit Plan', 'Phase 9'],
];

export default function Show({ engagement, derivation, inherentVersion, history = [], can = {} }) {
    const [tab, setTab] = useState('summary');

    return (
        <AppLayout title={engagement.reference}>
            <Head title={`${engagement.reference} — ${engagement.name}`} />

            <PageHeader
                title={engagement.name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs">{engagement.reference}</span>
                        <span className="text-gray-300">·</span>
                        {engagement.third_party?.url ? (
                            <a href={engagement.third_party.url} className="text-blue-700 hover:underline">
                                {engagement.third_party.legal_name}
                            </a>
                        ) : engagement.third_party?.legal_name}
                        <span className="text-gray-300">·</span>
                        <span>{engagement.type_label}</span>
                    </span>
                }
                actions={
                    <div className="flex items-center gap-2">
                        <TierBadge tier={engagement.effective_tier} label={engagement.effective_tier_label} />
                        <StatusBadge status={engagement.status} />
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
                        {[['summary', 'Summary'], ['inherent', 'Inherent Risk'], ['history', 'Score History']].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setTab(key)}
                                className={`px-3 py-2 text-sm font-medium ${
                                    tab === key
                                        ? 'border-b-2 border-blue-600 text-blue-700'
                                        : 'text-gray-500 hover:text-gray-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {tab === 'summary' && (
                        <div className="space-y-6">
                            <div className="card p-5">
                                <h3 className="mb-3 text-sm font-semibold text-gray-900">Service</h3>
                                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                    <Field label="Service description" value={engagement.service_description} span />
                                    <Field label="Service type" value={engagement.service_type} />
                                    <Field label="Business unit" value={engagement.business_unit} />
                                    <Field label="Relationship owner" value={engagement.relationship_owner} />
                                    <Field label="Executive sponsor" value={engagement.executive_sponsor} />
                                </dl>
                            </div>

                            <div className="card p-5">
                                <h3 className="mb-3 text-sm font-semibold text-gray-900">Data and regulatory profile</h3>
                                <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                    <Field label="Processes personal data" value={engagement.processes_personal_data ? 'Yes' : 'No'} />
                                    <Field label="Cross-border" value={engagement.cross_border ? 'Yes' : 'No'} />
                                    <Field
                                        label="Transfer basis"
                                        value={engagement.transfer_basis}
                                        // The absence of a lawful basis is the KO-PII-XB
                                        // condition, so it is called out rather than dashed.
                                        warn={engagement.cross_border && (!engagement.transfer_basis || engagement.transfer_basis === 'none')}
                                        warnText="No NDPA §41 basis recorded"
                                    />
                                    <Field label="PCI in scope" value={engagement.pci_in_scope ? 'Yes' : 'No'} />
                                    <Field label="Supports a critical function" value={engagement.supports_critical_function ? 'Yes' : 'No'} />
                                    <Field label="Next assessment due" value={engagement.next_assessment_due} />
                                </dl>
                            </div>

                            <div className="card p-5">
                                <h3 className="mb-3 text-sm font-semibold text-gray-900">
                                    Business functions supported
                                </h3>
                                {engagement.functions?.length ? (
                                    <table className="w-full text-sm">
                                        <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                                            <tr>
                                                <th className="pb-2 font-medium">Code</th>
                                                <th className="pb-2 font-medium">Function</th>
                                                <th className="pb-2 font-medium">Criticality</th>
                                                <th className="pb-2 font-medium">RTO</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {engagement.functions.map((fn) => (
                                                <tr key={fn.id}>
                                                    <td className="py-2 font-mono text-xs">{fn.code}</td>
                                                    <td className="py-2">{fn.name}</td>
                                                    <td className="py-2 capitalize">{fn.criticality}</td>
                                                    <td className="py-2">{fn.rto_hours ? `${fn.rto_hours} h` : '—'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                ) : (
                                    <p className="text-sm text-gray-500">No business functions are linked.</p>
                                )}
                            </div>
                        </div>
                    )}

                    {tab === 'inherent' && (
                        <div className="card p-5">
                            <h3 className="mb-3 text-sm font-semibold text-gray-900">Inherent risk assessment</h3>
                            {inherentVersion ? (
                                <>
                                    <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                        <Field label="Version" value={`v${inherentVersion.version}`} />
                                        <Field label="Ruleset" value={inherentVersion.ruleset_version} />
                                        <Field label="Weighted score" value={inherentVersion.raw_score} />
                                        <Field label="Resulting tier" value={inherentVersion.resulting_tier} />
                                        <Field label="Assessed" value={inherentVersion.assessed_at} span />
                                    </dl>
                                    <p className="mt-4 text-xs text-gray-500">
                                        The full derivation is in the score panel — open “Why this score”.
                                    </p>
                                </>
                            ) : (
                                <p className="text-sm text-gray-500">
                                    This engagement has not been tiered yet.
                                </p>
                            )}
                        </div>
                    )}

                    {tab === 'history' && (
                        <div className="card p-5">
                            <h3 className="mb-3 text-sm font-semibold text-gray-900">Score history</h3>
                            {history.length ? (
                                <table className="w-full text-sm">
                                    <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th className="pb-2 font-medium">When</th>
                                            <th className="pb-2 font-medium">Run</th>
                                            <th className="pb-2 font-medium">Ruleset</th>
                                            <th className="pb-2 text-right font-medium">IR</th>
                                            <th className="pb-2 text-right font-medium">RR</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {history.map((run) => (
                                            <tr key={run.id}>
                                                <td className="py-2 text-gray-600">{run.at}</td>
                                                <td className="py-2 capitalize">{run.run_type}</td>
                                                <td className="py-2 font-mono text-xs">{run.ruleset_version}</td>
                                                <td className="py-2 text-right tabular-nums">{run.ir ?? '—'}</td>
                                                <td className="py-2 text-right tabular-nums">{run.rr ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <p className="text-sm text-gray-500">No score runs recorded yet.</p>
                            )}
                        </div>
                    )}
                </div>

                <div className="space-y-4">
                    <ScorePanel engagement={engagement} derivation={derivation} />

                    <div className="card p-5">
                        <h3 className="text-sm font-semibold text-gray-900">Not yet available</h3>
                        <p className="mt-1 text-xs text-gray-500">
                            These tabs arrive with the phase that builds them. They are listed so the
                            workspace shows what is missing rather than showing an empty tab, which
                            would read as “nothing to see”.
                        </p>
                        <ul className="mt-3 space-y-1.5 text-xs">
                            {PENDING_TABS.map(([label, phase]) => (
                                <li key={label} className="flex items-center justify-between text-gray-600">
                                    <span>{label}</span>
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500">
                                        {phase}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

function Field({ label, value, span = false, warn = false, warnText }) {
    return (
        <div className={span ? 'col-span-2' : ''}>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`mt-0.5 ${warn ? 'font-medium text-red-700' : 'text-gray-900'}`}>
                {warn && warnText ? warnText : (value ?? '—')}
            </dd>
        </div>
    );
}
