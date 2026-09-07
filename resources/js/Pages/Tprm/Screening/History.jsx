import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * A third party's screening history — the CBN AML/CFT Reg. 35 retrieval path.
 *
 * "Records retained five years and retrievable within 48 hours." What has to
 * be retrievable is the PROVIDER'S ANSWER, not our summary of it: an examiner
 * asking what the list said in 2021 is not asking what we concluded. So each
 * check exposes its raw response, including how many entries the list held at
 * the time — which is what makes a historic clear result mean anything.
 */
export default function History({ thirdParty, status = {}, checks = [], retention = {}, can = {} }) {
    const [expanded, setExpanded] = useState(null);

    return (
        <AppLayout title={`Screening — ${thirdParty.legal_name}`}>
            <Head title={`Screening — ${thirdParty.legal_name}`} />

            <PageHeader
                title="Screening history"
                subtitle={<Link className="underline" href={thirdParty.url}>{thirdParty.legal_name}</Link>}
                actions={can.decide ? (
                    <button type="button" className="btn btn-primary"
                        onClick={() => router.post(route('tprm.screening.run', thirdParty.id))}>
                        Screen now
                    </button>
                ) : null}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Last screened" value={status.last_screened_at ?? 'Never'}
                    tone={status.last_screened_at ? null : 'warn'} />
                <Tile label="Checks on file" value={status.checks ?? 0}
                    hint={(status.providers ?? []).join(', ') || 'no providers'} />
                <Tile label="Owners screened"
                    value={`${status.owners_screened ?? 0} of ${status.owners_to_screen ?? 0}`}
                    hint="directors and beneficial owners"
                    tone={(status.owners_screened ?? 0) < (status.owners_to_screen ?? 0) ? 'warn' : null} />
                <Tile label="Awaiting a decision" value={status.pending_matches ?? 0}
                    tone={status.pending_matches ? 'warn' : null} />
            </div>

            {(status.failed_providers ?? []).length > 0 && (
                <div className="mb-6 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    These providers could not be searched on the most recent run:{' '}
                    {status.failed_providers.join(', ')}. A failed search is not a clear search.
                </div>
            )}

            <div className="card">
                <div className="border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-semibold text-gray-900">Every check on record</h3>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Retained {retention.years} years and retrievable on demand — {retention.citation}.
                    </p>
                </div>

                {checks.length === 0 ? (
                    <p className="p-8 text-center text-sm text-gray-500">
                        No screening has been run against this third party.
                    </p>
                ) : (
                    <div className="divide-y divide-gray-100">
                        {checks.map((check) => (
                            <div key={check.id} className="p-4">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm text-gray-900">
                                            {check.subject_name}
                                            <span className="ml-2 text-xs text-gray-500">
                                                {check.subject_type === 'ownership' ? 'director / UBO' : 'entity'}
                                            </span>
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-500">
                                            {check.provider} · {check.run_at}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusChip status={check.status} matches={check.matches} />
                                        {!check.retainable && (
                                            <span className="text-xs text-gray-400">beyond retention</span>
                                        )}
                                        <button type="button" className="btn btn-secondary text-xs"
                                            onClick={() => setExpanded(expanded === check.id ? null : check.id)}>
                                            {expanded === check.id ? 'Hide' : 'The provider’s answer'}
                                        </button>
                                    </div>
                                </div>

                                {expanded === check.id && (
                                    <pre className="mt-3 overflow-x-auto rounded bg-gray-50 p-3 text-[11px] text-gray-700">
                                        {JSON.stringify(check.raw_response, null, 2)}
                                    </pre>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function StatusChip({ status, matches }) {
    const [label, tone] = {
        clear: ['Clear', 'bg-green-100 text-green-800'],
        matches: [`${matches} match(es)`, 'bg-amber-100 text-amber-800'],
        // Never green. A failed search is not a clear search.
        failed: ['Search failed', 'bg-red-100 text-red-800'],
    }[status] ?? [status, 'bg-gray-100 text-gray-600'];

    return <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-lg font-semibold ${
                tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
            }`}>{value}</p>
            {hint && <p className="mt-0.5 text-xs text-gray-500">{hint}</p>}
        </div>
    );
}
