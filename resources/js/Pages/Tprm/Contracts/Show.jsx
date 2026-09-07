import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The contract workspace.
 *
 * It answers three questions in the order a reviewer asks them: what does this
 * commit us to, what is missing, and can the engagement go live. The third is
 * a consequence of the second and the page says so — an activation banner
 * naming the clauses, with a waiver action beside each, rather than a disabled
 * button and a tooltip.
 *
 * THE NOTICE PANEL SITS ABOVE THE CLAUSES. It is the thing on this screen with
 * a deadline attached, and the failure it prevents — a contract that renews
 * because nobody counted back from the expiry — is the one that cannot be
 * fixed afterwards.
 */
export default function Show({
    contract, family = [], clauses = {}, activation = {}, obligations = [], capabilities = {}, can = {},
}) {
    const [tab, setTab] = useState('clauses');

    const tabs = [
        ['clauses', `Clauses (${clauses.gap_count ?? 0} gap${clauses.gap_count === 1 ? '' : 's'})`],
        ['obligations', `Obligations (${obligations.length})`],
        ['terms', 'Terms'],
        ['family', `Documents (${family.length})`],
    ];

    return (
        <AppLayout title={contract.title}>
            <Head title={`${contract.reference} — ${contract.title}`} />

            <PageHeader
                title={contract.title}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs">{contract.reference}</span>
                        <span className="text-gray-300">·</span>
                        <span>{contract.third_party ?? 'Provider'}</span>
                        {contract.engagement?.reference && (
                            <>
                                <span className="text-gray-300">·</span>
                                <Link className="underline" href={contract.engagement.url}>
                                    {contract.engagement.reference}
                                </Link>
                            </>
                        )}
                    </span>
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        <a href={route('tprm.contracts.gap-report', contract.id)} target="_blank" rel="noreferrer" className="btn btn-secondary">
                            Gap report
                        </a>
                        {can.manage && (
                            <>
                                <button
                                    type="button"
                                    className="btn btn-secondary"
                                    onClick={() => router.post(route('tprm.contracts.analyse', contract.id))}
                                >
                                    {capabilities.ai_clause_analysis ? 'Analyse clauses' : 'List clauses'}
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-secondary"
                                    onClick={() => router.post(route('tprm.contracts.obligations.generate', contract.id))}
                                >
                                    Generate obligations
                                </button>
                            </>
                        )}
                    </div>
                }
            />

            <ActivationBanner activation={activation} />
            <NoticePanel contract={contract} />

            <div className="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
                {tabs.map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`px-3 py-2 text-sm font-medium ${
                            tab === key ? 'border-b-2 border-blue-600 text-blue-700' : 'text-gray-500 hover:text-gray-700'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'clauses' && (
                <ClausePanel contract={contract} clauses={clauses} capabilities={capabilities} can={can} />
            )}
            {tab === 'obligations' && <ObligationPanel obligations={obligations} />}
            {tab === 'terms' && <TermsPanel contract={contract} />}
            {tab === 'family' && <FamilyPanel family={family} />}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */

function ActivationBanner({ activation }) {
    if (activation.allowed) {
        return (
            <div className="mb-6 rounded-md border border-green-200 bg-green-50 p-4 text-sm text-green-900">
                Every clause that is a condition of activation is present and reviewed. This engagement can be
                activated.
            </div>
        );
    }

    return (
        <div className="mb-6 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            <p className="font-medium">This engagement cannot be activated yet.</p>
            <p className="mt-1 whitespace-pre-line">{activation.reason}</p>
        </div>
    );
}

/**
 * The panel FR-CTR-02 exists for.
 *
 * The notice deadline is stated before the expiry date and in larger type,
 * because on an auto-renewing contract the expiry date is not a decision point
 * at all — by then the renewal has happened.
 */
function NoticePanel({ contract }) {
    if (!contract.expiry_date) {
        return null;
    }

    const days = contract.days_until_notice;

    const tone = contract.notice_window_missed
        ? 'border-red-200 bg-red-50 text-red-900'
        : days !== null && days <= 30
            ? 'border-amber-200 bg-amber-50 text-amber-900'
            : 'border-gray-200 bg-gray-50 text-gray-800';

    return (
        <div className={`mb-6 rounded-md border p-4 text-sm ${tone}`}>
            {contract.notice_deadline === null ? (
                <p>
                    This contract expires on {contract.expiry_date}, and no notice period has been recorded — so
                    nothing can be alerted on. Record the notice period both sides must give and the register
                    will count back from it.
                </p>
            ) : contract.notice_window_missed ? (
                <p>
                    <span className="font-medium">The notice window closed on {contract.notice_deadline}.</span>{' '}
                    This contract renews on {contract.expiry_date} whether or not that was the intention. If the
                    relationship was to end, the exit now has to be negotiated rather than exercised.
                </p>
            ) : (
                <p>
                    <span className="font-medium">
                        Serve notice by {contract.notice_deadline}
                        {days !== null && ` — ${days} day${days === 1 ? '' : 's'} from now.`}
                    </span>{' '}
                    The contract expires on {contract.expiry_date}, but the {contract.notice_period_days_entity}-day
                    notice period means the decision has to be made and served before the date above.
                    {contract.renews_automatically
                        ? ' It renews automatically if notice is not served.'
                        : ' It does not renew automatically.'}
                </p>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */

function ClausePanel({ contract, clauses, capabilities, can }) {
    const rows = clauses.clauses ?? [];
    const [waiving, setWaiving] = useState(null);

    return (
        <div className="space-y-4">
            <div className="card p-4 text-sm text-gray-600">
                {rows.length} clause(s) apply to this engagement.
                {' '}{clauses.blocking_gap_count ?? 0} of the gaps are conditions of activation,
                {' '}and {clauses.unreviewed_gap_count ?? 0} gap(s) nobody has looked at yet.
                {!capabilities.ai_clause_analysis && (
                    <span className="block mt-1 text-gray-500">
                        Automatic clause analysis is switched off for this installation. Every clause below can
                        be determined by hand, and the gap report and the activation gate work identically.
                    </span>
                )}
            </div>

            {(clauses.unresolvable ?? []).length > 0 && (
                <div className="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-medium">Some clauses could not be assessed.</p>
                    <p className="mt-1">
                        Whether they apply depends on facts the engagement record does not hold yet. They are
                        listed rather than left out — a clause silently absent from a gap report is one nobody
                        knows to ask about.
                    </p>
                    <ul className="mt-2 list-disc pl-5">
                        {clauses.unresolvable.map((row) => (
                            <li key={row.code}>
                                {row.code} — needs {row.missing_facts.join(', ')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="card divide-y divide-gray-100">
                {rows.map((row) => (
                    <ClauseRow
                        key={row.id}
                        contract={contract}
                        row={row}
                        can={can}
                        onWaive={() => setWaiving(row)}
                    />
                ))}
            </div>

            {waiving && (
                <WaiverDialog contract={contract} row={waiving} onClose={() => setWaiving(null)} />
            )}
        </div>
    );
}

function ClauseRow({ contract, row, can, onWaive }) {
    const [determining, setDetermining] = useState(false);

    return (
        <div className="p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-gray-900">
                        {row.code} — {row.title}
                        {row.is_blocking && (
                            <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                Condition of activation
                            </span>
                        )}
                    </p>
                    {row.citation && (
                        <p className="mt-0.5 text-xs text-gray-500">
                            {row.citation}{row.regulatory_source ? ` — ${row.regulatory_source}` : ''}
                        </p>
                    )}
                </div>
                <PresenceChip row={row} />
            </div>

            {row.guidance && <p className="mt-2 text-sm text-gray-600">{row.guidance}</p>}

            {row.located_text && (
                <p className="mt-2 border-l-2 border-gray-200 pl-3 text-xs italic text-gray-600">
                    “{row.located_text}”
                    {row.page_reference && <span className="not-italic"> ({row.page_reference})</span>}
                </p>
            )}

            {row.determined_by && row.determined_by !== contract.reference && (
                // On a contract with amendments this is the answer a reviewer
                // needs: the master agreement is silent, and the 2026
                // amendment granted it.
                <p className="mt-2 text-xs text-gray-500">Determined against {row.determined_by}.</p>
            )}

            {row.detected_by === 'ai' && row.reviewer_status === 'pending' && (
                <p className="mt-2 text-xs text-amber-700">
                    Read from the contract but not yet reviewed, so it does not count towards activation. A model
                    that reads &ldquo;the Provider shall not be obliged to permit audits&rdquo; as an
                    audit-rights clause produces exactly this output.
                </p>
            )}

            {can.manage && (
                <div className="mt-3 flex flex-wrap gap-2">
                    {row.contract_clause_id && row.reviewer_status === 'pending' && row.detected_by === 'ai' && (
                        <>
                            <button
                                type="button"
                                className="btn btn-secondary text-xs"
                                onClick={() => router.post(
                                    route('tprm.contracts.clauses.review', [contract.id, row.contract_clause_id]),
                                    { accept: true },
                                )}
                            >
                                Accept
                            </button>
                            <button
                                type="button"
                                className="btn btn-secondary text-xs"
                                onClick={() => router.post(
                                    route('tprm.contracts.clauses.review', [contract.id, row.contract_clause_id]),
                                    { accept: false },
                                )}
                            >
                                Reject
                            </button>
                        </>
                    )}

                    <button type="button" className="btn btn-secondary text-xs" onClick={() => setDetermining(true)}>
                        Record by hand
                    </button>

                    {row.blocks_activation && can.waive && (
                        <button type="button" className="btn btn-secondary text-xs" onClick={onWaive}>
                            Waive
                        </button>
                    )}
                </div>
            )}

            {determining && (
                <DetermineDialog contract={contract} row={row} onClose={() => setDetermining(false)} />
            )}
        </div>
    );
}

function PresenceChip({ row }) {
    const [label, tone] = row.waived
        ? ['Waived', 'bg-amber-100 text-amber-800']
        : row.satisfied
            ? ['Present', 'bg-green-100 text-green-800']
            : row.presence === 'partial'
                ? ['Partial', 'bg-amber-100 text-amber-800']
                : row.presence === 'not_applicable'
                    ? ['Not applicable', 'bg-gray-100 text-gray-600']
                    : row.reviewer_status === 'pending' && row.presence === 'present'
                        ? ['Detected, unreviewed', 'bg-amber-100 text-amber-800']
                        : ['Absent', 'bg-red-100 text-red-800'];

    return <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

function DetermineDialog({ contract, row, onClose }) {
    const form = useForm({
        presence: row.presence === 'not_analysed' ? 'present' : row.presence,
        located_text: row.located_text ?? '',
        page_reference: row.page_reference ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.contracts.clauses.determine', [contract.id, row.id]), { onSuccess: onClose });
    };

    return (
        <Dialog title={`${row.code} — record a determination`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-4">
                <label className="block">
                    <span className="text-sm font-medium text-gray-700">What does the contract say?</span>
                    <select
                        className="input mt-1"
                        value={form.data.presence}
                        onChange={(event) => form.setData('presence', event.target.value)}
                    >
                        <option value="present">Present — it contains the obligation</option>
                        <option value="partial">Partial — it addresses the subject but stops short</option>
                        <option value="absent">Absent — it does not address this</option>
                        <option value="not_applicable">Not applicable to this contract</option>
                    </select>
                    <p className="mt-1 text-xs text-gray-500">
                        A notification duty with no timeframe, or an audit right needing the provider&rsquo;s
                        consent, is partial rather than present. Partial blocks activation just as absent does.
                    </p>
                </label>

                <label className="block">
                    <span className="text-sm font-medium text-gray-700">The wording you relied on</span>
                    <textarea
                        rows={4}
                        className="input mt-1"
                        value={form.data.located_text}
                        onChange={(event) => form.setData('located_text', event.target.value)}
                    />
                </label>

                <label className="block">
                    <span className="text-sm font-medium text-gray-700">Clause or page</span>
                    <input
                        type="text"
                        className="input mt-1"
                        value={form.data.page_reference}
                        onChange={(event) => form.setData('page_reference', event.target.value)}
                    />
                </label>

                <DialogActions onClose={onClose} processing={form.processing} submitLabel="Record" />
            </form>
        </Dialog>
    );
}

function WaiverDialog({ contract, row, onClose }) {
    const form = useForm({ rationale: '', compensating_controls: '', expires_at: '', approver_role: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.contracts.clauses.waive', [contract.id, row.contract_clause_id]), { onSuccess: onClose });
    };

    return (
        <Dialog title={`Waive ${row.code}`} onClose={onClose}>
            <p className="mb-4 text-sm text-gray-600">
                A waiver admits the engagement without the term. It stays a gap on every report, appears on the
                override register, and is reported to the risk committee. Both the rationale and the expiry are
                required — a waiver with no expiry is a deletion with extra steps.
            </p>

            <form onSubmit={submit} className="space-y-4">
                <label className="block">
                    <span className="text-sm font-medium text-gray-700">Why is this acceptable?</span>
                    <textarea
                        rows={4}
                        className="input mt-1"
                        value={form.data.rationale}
                        onChange={(event) => form.setData('rationale', event.target.value)}
                    />
                    {form.errors.rationale && <p className="mt-1 text-xs text-red-600">{form.errors.rationale}</p>}
                </label>

                <label className="block">
                    <span className="text-sm font-medium text-gray-700">Compensating controls</span>
                    <textarea
                        rows={3}
                        className="input mt-1"
                        value={form.data.compensating_controls}
                        onChange={(event) => form.setData('compensating_controls', event.target.value)}
                    />
                </label>

                <div className="grid grid-cols-2 gap-4">
                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Expires</span>
                        <input
                            type="date"
                            className="input mt-1"
                            value={form.data.expires_at}
                            onChange={(event) => form.setData('expires_at', event.target.value)}
                        />
                        {form.errors.expires_at && <p className="mt-1 text-xs text-red-600">{form.errors.expires_at}</p>}
                    </label>

                    <label className="block">
                        <span className="text-sm font-medium text-gray-700">Approver role</span>
                        <input
                            type="text"
                            className="input mt-1"
                            placeholder="Chief Risk Officer"
                            value={form.data.approver_role}
                            onChange={(event) => form.setData('approver_role', event.target.value)}
                        />
                    </label>
                </div>

                <DialogActions onClose={onClose} processing={form.processing} submitLabel="Record the waiver" />
            </form>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */

function ObligationPanel({ obligations }) {
    if (obligations.length === 0) {
        return (
            <div className="card p-5 text-sm text-gray-500">
                No obligations generated yet. They come from the clauses this contract actually contains — a
                duty from a clause nobody signed is a duty the provider never agreed to.
            </div>
        );
    }

    const ours = obligations.filter((o) => o.obligor === 'entity');
    const theirs = obligations.filter((o) => o.obligor !== 'entity');

    return (
        <div className="space-y-6">
            <ObligationList title={`Owed by us (${ours.length})`} rows={ours} emphasise />
            <ObligationList title={`Owed by the provider (${theirs.length})`} rows={theirs} />
        </div>
    );
}

function ObligationList({ title, rows, emphasise = false }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
            {emphasise && (
                <p className="mt-0.5 text-xs text-gray-500">
                    These are the ones an institution is found to have breached.
                </p>
            )}
            <ul className="mt-3 divide-y divide-gray-100">
                {rows.map((row) => (
                    <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span className="text-gray-900">{row.title}</span>
                        <span className="flex items-center gap-3 text-xs text-gray-500">
                            <span>{row.frequency.replace('_', ' ')}</span>
                            <span>{row.next_due_date ?? 'when it happens'}</span>
                            <span className={row.owner ? '' : 'text-amber-700'}>{row.owner ?? 'Unassigned'}</span>
                            {row.breach_count > 0 && (
                                <span className="text-red-700">{row.breach_count} breach(es)</span>
                            )}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function TermsPanel({ contract }) {
    const rows = [
        ['Type', contract.type],
        ['Status', contract.status],
        ['Effective', contract.effective_date ?? '—'],
        ['Expires', contract.expiry_date ?? 'No end date'],
        ['Renewal', contract.renewal_type],
        ['Renewal term', contract.renewal_term_months ? `${contract.renewal_term_months} months` : '—'],
        ['Our notice period', contract.notice_period_days_entity ? `${contract.notice_period_days_entity} days` : 'Not recorded'],
        ['Their notice period', contract.notice_period_days_provider ? `${contract.notice_period_days_provider} days` : 'Not recorded'],
        ['Governing law', contract.governing_law_country ?? '—'],
        ['Dispute forum', contract.dispute_forum ?? '—'],
        ['Counterparty signatory', contract.counterparty_signatory ?? '—'],
        ['Our signatory', contract.internal_signatory ?? '—'],
    ];

    return (
        <div className="card p-5">
            <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                {rows.map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
                        <dd className="mt-0.5 text-sm text-gray-900">{value || '—'}</dd>
                    </div>
                ))}
            </dl>

            {contract.document && (
                <p className="mt-5 border-t border-gray-100 pt-4 text-sm">
                    Signed document:{' '}
                    <Link className="text-blue-700 underline" href={contract.document.url}>
                        {contract.document.title}
                    </Link>
                </p>
            )}
        </div>
    );
}

function FamilyPanel({ family }) {
    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">The agreement and everything under it</h3>
            <p className="mt-0.5 text-xs text-gray-500">
                Newest first. An amendment&rsquo;s determination of a clause beats the master agreement&rsquo;s
                silence on it, which is why the effective clause set is read down this chain rather than off one
                document.
            </p>
            <ul className="mt-3 divide-y divide-gray-100">
                {family.map((row) => (
                    <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <Link href={row.url} className={row.is_current ? 'font-medium text-gray-900' : 'text-blue-700 underline'}>
                            {row.reference} — {row.title}
                        </Link>
                        <span className="text-xs text-gray-500">
                            {row.type.replace('_', ' ')} · {row.effective_date ?? 'no date'} · {row.status}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/* ------------------------------------------------------------------ */

function Dialog({ title, onClose, children }) {
    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <div className="card w-full max-w-2xl p-6">
                <h2 className="mb-4 text-base font-semibold text-gray-900">{title}</h2>
                {children}
            </div>
        </div>
    );
}

function DialogActions({ onClose, processing, submitLabel }) {
    return (
        <div className="mt-6 flex justify-end gap-2">
            <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={processing}>{submitLabel}</button>
        </div>
    );
}
