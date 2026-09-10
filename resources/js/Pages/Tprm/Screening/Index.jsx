import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The screening match queue — FR-DDL-05, FR-MON-05.
 *
 * THE CONSEQUENCE IS ON THE SCREEN BEFORE THE DECISION IS MADE. Confirming a
 * true match suspends every engagement with the vendor, blacklists it, forces
 * its residual score to the maximum and starts a 24-hour regulatory clock. A
 * reviewer who learns that afterwards has been ambushed by their own tool, so
 * the resolution panel states it first with the count of engagements that will
 * stop.
 *
 * A RATIONALE IS REQUIRED ON A DISMISSAL TOO. "Different date of birth, no
 * connection to the entity" is what makes a false positive reviewable two
 * years later by an examiner who cannot re-run the search as it was.
 */
export default function Index({ pending = [], summary = {}, lists = [], can = {} }) {
    const [deciding, setDeciding] = useState(null);

    return (
        <AppLayout title="Screening">
            <Head title="Screening" />

            <PageHeader
                title="Screening"
                subtitle="Sanctions and PEP matches awaiting a decision. The entity and every director or ultimate beneficial owner are screened separately — CBN AML/CFT Reg. 29."
            />

            <ListHealth lists={lists} />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Awaiting a decision" value={summary.pending ?? 0}
                    tone={summary.pending ? 'warn' : null} hint="matches nobody has resolved" />
                <Tile label="Confirmed true matches" value={summary.true_matches ?? 0}
                    tone={summary.true_matches ? 'critical' : null} hint="engagements suspended" />
                <Tile label="Checks (30 days)" value={summary.checks_30d ?? 0} hint="across every provider" />
                <Tile label="Never screened" value={summary.never_screened ?? 0}
                    tone={summary.never_screened ? 'warn' : null} hint="active third parties" />
            </div>

            {pending.length === 0 ? (
                <div className="card p-8 text-center text-sm text-gray-500">
                    No matches are waiting for a decision.
                </div>
            ) : (
                <div className="card divide-y divide-gray-100">
                    {pending.map((match) => (
                        <div key={match.id} className="p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-gray-900">{match.matched_name}</p>
                                    <p className="mt-0.5 text-xs text-gray-600">
                                        matched against <span className="font-medium">{match.subject_name}</span>
                                        {match.subject_type === 'ownership' && ' (a director or beneficial owner)'}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        {match.list_name} · {match.provider} · {match.run_at}
                                    </p>
                                </div>
                                <span className="shrink-0 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                    {match.score}% name similarity
                                </span>
                            </div>

                            {match.details && (
                                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs lg:grid-cols-4">
                                    {Object.entries(match.details)
                                        .filter(([, value]) => value && !Array.isArray(value))
                                        .map(([key, value]) => (
                                            <div key={key}>
                                                <dt className="text-gray-500">{key.replace(/_/g, ' ')}</dt>
                                                <dd className="text-gray-900">{String(value)}</dd>
                                            </div>
                                        ))}
                                </dl>
                            )}

                            {can.decide && (
                                <button type="button" className="btn btn-primary mt-4 text-xs"
                                    onClick={() => setDeciding(match)}>
                                    Resolve this match
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {deciding && <DecisionDialog match={deciding} onClose={() => setDeciding(null)} />}
        </AppLayout>
    );
}

/**
 * The list-health banner.
 *
 * An empty list is the dangerous state: a search of it finds nothing, which
 * looks exactly like a clean result unless somebody says otherwise.
 */
function ListHealth({ lists }) {
    const problems = lists.filter((list) => list.empty || list.stale);

    if (problems.length === 0) {
        return null;
    }

    return (
        <div className="mb-6 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            {problems.map((list) => (
                <p key={list.code} className={problems.length > 1 ? 'mt-1 first:mt-0' : ''}>
                    <span className="font-medium">{list.name}:</span> {list.note}
                </p>
            ))}
            <p className="mt-2 text-xs">
                Screening against an unusable list reports a failure rather than a clear result — a clear
                result against an empty list is not a clear result.
            </p>
        </div>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${
                tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
            }`}>{value}</p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

function DecisionDialog({ match, onClose }) {
    const form = useForm({ decision: 'false_positive', rationale: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.screening.decide', match.id), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">
                    {match.matched_name}
                </h2>
                <p className="mt-1 text-sm text-gray-600">
                    Matched against {match.subject_name} on {match.list_name}.
                </p>

                {/* Stated BEFORE the decision, not after it. */}
                {match.consequence && (
                    <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                        <p className="font-medium">
                            Confirming this as a true match will suspend {match.consequence.engagements}{' '}
                            engagement(s) immediately.
                        </p>
                        <p className="mt-1">{match.consequence.note}</p>
                    </div>
                )}

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Decision</span>
                    <select className="input mt-1" value={form.data.decision}
                        onChange={(event) => form.setData('decision', event.target.value)}>
                        <option value="false_positive">False positive — a different party</option>
                        <option value="possible">Possible — needs more information</option>
                        <option value="true_match">True match — this is the designated party</option>
                    </select>
                </label>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Rationale</span>
                    <textarea rows={4} className="input mt-1"
                        placeholder="Different date of birth and nationality; no connection to this entity."
                        value={form.data.rationale}
                        onChange={(event) => form.setData('rationale', event.target.value)} />
                    <p className="mt-1 text-xs text-gray-500">
                        Required on a dismissal as much as a confirmation — it is what makes the decision
                        reviewable by an examiner who cannot re-run the search as it was.
                    </p>
                    {form.errors.rationale && <p className="mt-1 text-xs text-red-600">{form.errors.rationale}</p>}
                </label>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit"
                        className={form.data.decision === 'true_match' ? 'btn btn-danger' : 'btn btn-primary'}
                        disabled={form.processing}>
                        {form.data.decision === 'true_match' ? 'Confirm and suspend' : 'Record the decision'}
                    </button>
                </div>
            </form>
        </div>
    );
}
