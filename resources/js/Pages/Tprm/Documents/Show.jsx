import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The document workspace — the viewer beside the extraction panel.
 *
 * THE PANEL'S JOB IS TO MAKE THE CONFIRMATION A REAL DECISION rather than a
 * formality. Three things are on the page before anybody clicks confirm: the
 * quote each field came from, the citation check's verdict, and any
 * instruction-like content found in the upload. A confirmation screen that
 * shows only the extracted values is a screen that gets clicked through, and
 * then the model's output is the bank's regulatory answer.
 *
 * THE TWO CONFIRMATIONS ARE TWO SEPARATE PANELS AND TWO SEPARATE BUTTONS. The
 * first says the extractor read the report correctly; the second says to apply
 * that to the register. They are different judgements made with different
 * information, and a single "Accept" would collapse them.
 */
export default function Show({
    document, extractions = [], soc2, proposals, scopeCheck, capabilities = {}, can = {},
}) {
    const latest = extractions[0] ?? null;
    const pending = extractions.find((extraction) => extraction.status === 'pending') ?? null;

    return (
        <AppLayout title={document.title}>
            <Head title={document.title} />

            <PageHeader
                title={document.title}
                subtitle={[document.type, document.issuer].filter(Boolean).join(' · ') || 'Uncategorised evidence'}
                actions={
                    <div className="flex gap-2">
                        <a href={document.download_url} className="btn btn-secondary">Download</a>
                        <Link href={route('tprm.documents.index')} className="btn btn-secondary">Back to library</Link>
                    </div>
                }
            />

            <ValidityBanner document={document} />
            <ScopeBanner check={scopeCheck} />
            <InjectionBanner extraction={latest} />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-1">
                    <DocumentFacts document={document} />
                </div>

                <div className="space-y-6 lg:col-span-2">
                    <ExtractionPanel
                        document={document}
                        extractions={extractions}
                        pending={pending}
                        capabilities={capabilities}
                        can={can}
                    />

                    {soc2 && <Soc2Panel soc2={soc2} />}

                    {soc2 && proposals && (
                        <ProposalsPanel document={document} proposals={proposals} soc2={soc2} can={can} />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Banners — the things that must be read before anything is clicked  */
/* ------------------------------------------------------------------ */

function ValidityBanner({ document }) {
    if (document.is_superseded) {
        return (
            <Banner tone="neutral">
                A newer version of this document exists
                {document.superseded_by
                    ? <> — <Link className="underline" href={route('tprm.documents.show', document.superseded_by.uuid)}>{document.superseded_by.title}</Link>.</>
                    : '.'}
                {' '}This one is kept because assessments still cite it.
            </Banner>
        );
    }

    if (document.is_expired) {
        return (
            <Banner tone="critical">
                This document expired on {document.valid_to}. Any control relying on it is no longer evidenced,
                and the engagement&rsquo;s score reflects that.
            </Banner>
        );
    }

    if (document.days_until_expiry !== null && document.days_until_expiry <= 90) {
        return (
            <Banner tone="warn">
                Expires in {document.days_until_expiry} days, on {document.valid_to}. Ask the vendor for the
                replacement now rather than on the day.
            </Banner>
        );
    }

    return null;
}

function ScopeBanner({ check }) {
    if (!check || !check.mismatch) {
        return null;
    }

    return (
        <Banner tone="warn">
            <span className="font-medium">Scope may not cover this service.</span>{' '}
            {check.reason}{' '}
            Confirming a mismatch applies a ×{check.modifier} modifier to the confidence in every answer this
            document evidences.
        </Banner>
    );
}

function InjectionBanner({ extraction }) {
    const flags = extraction?.extracted?._meta?.injection_flags ?? [];

    if (flags.length === 0) {
        return null;
    }

    return (
        <Banner tone="critical">
            <span className="font-medium">This document contains instruction-like text.</span> It was removed
            before the document was read, and it is shown here because a document trying to steer an automated
            reader is a fact about the vendor rather than a technical detail. Read these before confirming
            anything below.
            <ul className="mt-2 list-disc pl-5 font-mono text-xs">
                {flags.map((flag) => <li key={flag}>{flag}</li>)}
            </ul>
        </Banner>
    );
}

function Banner({ tone, children }) {
    const classes = {
        critical: 'border-red-200 bg-red-50 text-red-900',
        warn: 'border-amber-200 bg-amber-50 text-amber-900',
        neutral: 'border-gray-200 bg-gray-50 text-gray-800',
    }[tone];

    return <div className={`mb-6 rounded-md border p-4 text-sm ${classes}`}>{children}</div>;
}

/* ------------------------------------------------------------------ */

function DocumentFacts({ document }) {
    const rows = [
        ['Type', document.type ?? 'Uncategorised'],
        ['Attached to', `${document.owner_label} #${document.owner_id}`],
        ['Issuer', document.issuer ?? '—'],
        ['Issued', document.issue_date ?? '—'],
        ['Valid from', document.valid_from ?? '—'],
        ['Expires', document.valid_to ?? 'No expiry recorded'],
        ['Version', `v${document.version}`],
        ['Uploaded by', document.uploaded_by ?? 'System'],
        ['Uploaded', document.uploaded_at ?? '—'],
    ];

    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">Document</h3>
            <dl className="mt-3 space-y-2 text-sm">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4">
                        <dt className="text-gray-500">{label}</dt>
                        <dd className="text-right text-gray-900">{value}</dd>
                    </div>
                ))}
            </dl>

            {document.scope_text && (
                <div className="mt-4 border-t border-gray-100 pt-4">
                    <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Scope, as stated</p>
                    <p className="mt-1 whitespace-pre-line text-sm text-gray-700">{document.scope_text}</p>
                </div>
            )}

            <div className="mt-4 border-t border-gray-100 pt-4 text-xs text-gray-500">
                <p>
                    SHA-256 <span className="font-mono">{document.hash ? `${document.hash.slice(0, 16)}…` : 'not recorded'}</span>
                </p>
                <p className="mt-1">
                    {document.virus_scan_status === 'pending'
                        // Said plainly rather than shown as a green tick. No
                        // scanner is wired in this build, and a "clean" badge
                        // on an unscanned file is the kind of claim an auditor
                        // takes apart.
                        ? 'Not virus scanned — no scanner is configured on this installation.'
                        : `Virus scan: ${document.virus_scan_status}.`}
                </p>
                <p className="mt-1">Downloads are logged and the link above expires in five minutes.</p>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  The FIRST confirmation                                             */
/* ------------------------------------------------------------------ */

function ExtractionPanel({ document, extractions, pending, capabilities, can }) {
    const [manual, setManual] = useState(false);
    const canExtract = capabilities.ai_enabled && document.extractor && document.extractor !== 'generic';

    return (
        <div className="card p-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900">What this document says</h3>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Nothing here reaches the register until it is confirmed, and confirming it only records
                        what the document says — applying that is a second step.
                    </p>
                </div>

                {can.confirm && (
                    <div className="flex shrink-0 gap-2">
                        {canExtract && (
                            <button
                                type="button"
                                className="btn btn-secondary"
                                onClick={() => router.post(route('tprm.documents.extract', document.uuid))}
                            >
                                Read the document
                            </button>
                        )}
                        <button type="button" className="btn btn-secondary" onClick={() => setManual(true)}>
                            Enter by hand
                        </button>
                    </div>
                )}
            </div>

            {!capabilities.ai_enabled && (
                <p className="mt-3 rounded bg-gray-50 p-3 text-xs text-gray-600">
                    Automatic reading is switched off for this installation. Enter the details by hand — every
                    workflow below behaves identically either way.
                </p>
            )}

            {extractions.length === 0 && (
                <p className="mt-4 text-sm text-gray-500">Nothing has been recorded from this document yet.</p>
            )}

            {extractions.map((extraction) => (
                <ExtractionCard
                    key={extraction.id}
                    document={document}
                    extraction={extraction}
                    isPending={pending?.id === extraction.id}
                    can={can}
                />
            ))}

            {manual && (
                <ManualEntryDialog
                    document={document}
                    onClose={() => setManual(false)}
                />
            )}
        </div>
    );
}

function ExtractionCard({ document, extraction, isPending, can }) {
    const meta = extraction.extracted?._meta ?? {};
    const check = meta.citation_check ?? {};
    const fields = Object.entries(extraction.extracted ?? {}).filter(([key]) => key !== '_meta');
    const quoteFor = (field) => (check.verified ?? []).find((entry) => entry.field === field)?.quote;
    const rejectedFor = (field) => (check.rejected ?? []).find((entry) => entry.field === field);

    return (
        <div className="mt-4 rounded-md border border-gray-200 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-sm">
                    <span className="font-medium text-gray-900">{extraction.extractor_label ?? extraction.extractor}</span>
                    <span className="ml-2 text-gray-500">
                        {extraction.entered_manually
                            ? 'entered by hand'
                            : `read by ${extraction.model ?? 'a model'} · prompt ${extraction.prompt_version ?? '—'}`}
                    </span>
                </div>
                <StatusChip status={extraction.status} />
            </div>

            {/* The citation verdict, before the fields rather than after. */}
            {!extraction.entered_manually && (
                <p className={`mt-2 text-xs ${check.trustworthy === false ? 'text-red-700' : 'text-gray-600'}`}>
                    {check.trustworthy === false
                        ? `${check.rejected_count} quoted passage(s) could not be found in the document. Treat every field here as unverified — a model that invented one field invented it in the same pass that produced the rest.`
                        : `${check.verified_count ?? 0} quoted passage(s) located in the document.`}
                    {extraction.confidence !== null && ` Confidence ${extraction.confidence}.`}
                    {meta.below_threshold && ' Below the confirmation threshold.'}
                </p>
            )}

            <dl className="mt-3 space-y-2">
                {fields.map(([key, value]) => {
                    const rejected = rejectedFor(key);
                    const quote = quoteFor(key);

                    return (
                        <div key={key} className="text-sm">
                            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">
                                {key.replace(/_/g, ' ')}
                            </dt>
                            <dd className="text-gray-900">{renderValue(value)}</dd>
                            {quote && (
                                <p className="mt-0.5 border-l-2 border-gray-200 pl-2 text-xs italic text-gray-600">
                                    “{quote}”
                                </p>
                            )}
                            {rejected && (
                                <p className="mt-0.5 border-l-2 border-red-300 pl-2 text-xs text-red-700">
                                    {rejected.reason}
                                </p>
                            )}
                        </div>
                    );
                })}
            </dl>

            {extraction.corrections && (
                <div className="mt-3 border-t border-gray-100 pt-3">
                    <p className="text-xs font-medium text-gray-500">Corrected on confirmation</p>
                    <ul className="mt-1 space-y-0.5 text-xs text-gray-600">
                        {Object.entries(extraction.corrections).map(([field, change]) => (
                            <li key={field}>
                                <span className="font-medium">{field}</span>: {renderValue(change.from)} → {renderValue(change.to)}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {isPending && can.confirm && (
                <div className="mt-4 flex gap-2 border-t border-gray-100 pt-4">
                    <button
                        type="button"
                        className="btn btn-primary"
                        onClick={() => router.post(route('tprm.documents.extractions.confirm', [document.uuid, extraction.id]))}
                    >
                        Confirm this is what the document says
                    </button>
                    <button
                        type="button"
                        className="btn btn-secondary"
                        onClick={() => router.post(route('tprm.documents.extractions.reject', [document.uuid, extraction.id]))}
                    >
                        Reject
                    </button>
                </div>
            )}
        </div>
    );
}

function StatusChip({ status }) {
    const label = {
        pending: 'Awaiting confirmation',
        confirmed: 'Confirmed',
        rejected: 'Rejected',
        superseded: 'Superseded',
    }[status] ?? status;

    const tone = {
        pending: 'bg-amber-100 text-amber-800',
        confirmed: 'bg-green-100 text-green-800',
        rejected: 'bg-gray-100 text-gray-700',
    }[status] ?? 'bg-gray-100 text-gray-700';

    return <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

/* ------------------------------------------------------------------ */

function Soc2Panel({ soc2 }) {
    return (
        <div className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">SOC 2 report</h3>

            <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <Fact label="Type" value={soc2.report_type === 'type_ii' ? 'Type II' : 'Type I'} />
                <Fact label="Service auditor" value={soc2.service_auditor ?? '—'} />
                <Fact label="Period" value={`${soc2.period_start ?? '?'} to ${soc2.period_end ?? '?'}`} />
                <Fact
                    label="Opinion"
                    value={soc2.opinion_type ?? 'Not stated'}
                    tone={soc2.clean_opinion ? null : 'critical'}
                />
                <Fact label="Criteria in scope" value={(soc2.covered_criteria ?? []).join(', ') || '—'} />
                <Fact
                    label="Days since period ended"
                    value={soc2.gap_days === null ? '—' : `${soc2.gap_days}`}
                    // The number a reviewer should see beside the words
                    // "bridge letter": a two-week gap and a nine-month gap are
                    // not the same reliance.
                    tone={soc2.gap_days > 120 ? 'warn' : null}
                />
            </dl>

            {(soc2.exceptions ?? []).length > 0 && (
                <Section title={`Section 4 exceptions (${soc2.exceptions.length})`}>
                    {soc2.exceptions.map((exception) => (
                        <li key={exception.id} className="text-sm text-gray-700">
                            <span className="font-medium">{exception.control_reference ?? 'Unreferenced'}</span>
                            {' — '}{exception.description}
                            {exception.exceptions_noted && (
                                <span className="text-gray-500"> ({exception.exceptions_noted} of {exception.population ?? 'the sample'})</span>
                            )}
                        </li>
                    ))}
                </Section>
            )}

            {(soc2.cuecs ?? []).length > 0 && (
                <Section
                    title={`Complementary user entity controls (${soc2.cuecs.length})`}
                    note="Controls this report assumes WE operate. The service auditor did not test them."
                >
                    {soc2.cuecs.map((cuec) => (
                        <li key={cuec.id} className="text-sm text-gray-700">
                            <span className="font-medium">{cuec.reference ?? 'CUEC'}</span> — {cuec.description}
                            <span className={cuec.owner ? 'text-gray-500' : 'text-amber-700'}>
                                {cuec.owner ? ` Owned by ${cuec.owner}.` : ' Nobody here owns this yet.'}
                            </span>
                        </li>
                    ))}
                </Section>
            )}

            {(soc2.subservice_orgs ?? []).length > 0 && (
                <Section
                    title={`Subservice organisations (${soc2.subservice_orgs.length})`}
                    note="A carve-out means the auditor examined nothing this organisation does."
                >
                    {soc2.subservice_orgs.map((org) => (
                        <li key={org.id} className="text-sm text-gray-700">
                            <span className="font-medium">{org.name}</span>
                            {org.services ? ` — ${org.services}` : ''}
                            <span className={org.method === 'carve_out' ? 'text-amber-700' : 'text-gray-500'}>
                                {org.method === 'carve_out' ? ' (carved out)' : ' (inclusive)'}
                            </span>
                        </li>
                    ))}
                </Section>
            )}
        </div>
    );
}

function Fact({ label, value, tone }) {
    return (
        <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</dt>
            <dd className={`text-sm ${tone === 'critical' ? 'text-red-700' : tone === 'warn' ? 'text-amber-700' : 'text-gray-900'}`}>
                {value}
            </dd>
        </div>
    );
}

function Section({ title, note, children }) {
    return (
        <div className="mt-5 border-t border-gray-100 pt-4">
            <h4 className="text-sm font-semibold text-gray-900">{title}</h4>
            {note && <p className="mt-0.5 text-xs text-gray-500">{note}</p>}
            <ul className="mt-2 space-y-1.5">{children}</ul>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  The SECOND confirmation                                            */
/* ------------------------------------------------------------------ */

function ProposalsPanel({ document, proposals, soc2, can }) {
    const form = useForm({ answers: [], cuecs: {} });

    if (proposals.total === 0) {
        return null;
    }

    const toggle = (id) => {
        form.setData('answers', form.data.answers.includes(id)
            ? form.data.answers.filter((entry) => entry !== id)
            : [...form.data.answers, id]);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('tprm.documents.cascade', document.uuid));
    };

    return (
        <form onSubmit={submit} className="card p-5">
            <h3 className="text-sm font-semibold text-gray-900">What this report proposes</h3>
            <p className="mt-0.5 text-xs text-gray-500">
                Confirming above said the report says this. Applying below says to act on it. Choose what to
                apply — accepting all twelve because you agree with eleven is how a misread report reaches the
                register.
            </p>

            {proposals.bridge_letter_cap && (
                <p className="mt-3 rounded bg-amber-50 p-3 text-xs text-amber-900">
                    A bridge letter is covering the period since the report ended, so these pre-answers are
                    capped at &ldquo;documented&rdquo; rather than &ldquo;independently assured&rdquo;. A bridge
                    letter is the vendor&rsquo;s assertion that nothing changed; it is not an auditor&rsquo;s
                    opinion that nothing did.
                </p>
            )}

            {proposals.answers.length > 0 && (
                <div className="mt-5">
                    <h4 className="text-sm font-semibold text-gray-900">
                        Pre-answers ({proposals.answers.length})
                    </h4>
                    <ul className="mt-2 space-y-2">
                        {proposals.answers.map((answer) => (
                            <li key={answer.response_id} className="flex gap-3 rounded border border-gray-200 p-3">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={form.data.answers.includes(answer.response_id)}
                                    onChange={() => toggle(answer.response_id)}
                                />
                                <div className="text-sm">
                                    <p className="text-gray-900">
                                        <span className="font-medium">{answer.question_code}</span> — {answer.question}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-600">{answer.citation}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {proposals.findings.length > 0 && (
                <PendingList
                    title={`Findings (${proposals.findings.length})`}
                    note="One per Section 4 exception. The findings module arrives in a later phase; these are held against the report until it does."
                    items={proposals.findings.map((finding) => ({
                        key: finding.soc2_exception_id,
                        text: `${finding.title} — proposed ${finding.proposed_severity}`,
                    }))}
                />
            )}

            {proposals.nth_party_edges.length > 0 && (
                <PendingList
                    title={`Sub-processor edges (${proposals.nth_party_edges.length})`}
                    note="One per carve-out. The nth-party register arrives in a later phase; these are held against the report until it does."
                    items={proposals.nth_party_edges.map((edge) => ({
                        key: edge.soc2_subservice_org_id,
                        text: edge.child_name_raw,
                    }))}
                />
            )}

            {(soc2.cuecs ?? []).length > 0 && (
                <div className="mt-5">
                    <h4 className="text-sm font-semibold text-gray-900">
                        Internal obligations ({soc2.cuecs.length})
                    </h4>
                    <p className="mt-0.5 text-xs text-gray-500">
                        Give each one an owner here. An unowned CUEC is a duty the auditor assumed we perform
                        and nobody here has agreed to.
                    </p>
                    <ul className="mt-2 space-y-2">
                        {soc2.cuecs.map((cuec) => (
                            <li key={cuec.id} className="rounded border border-gray-200 p-3 text-sm">
                                <p className="text-gray-900">
                                    <span className="font-medium">{cuec.reference ?? 'CUEC'}</span> — {cuec.description}
                                </p>
                                <input
                                    type="number"
                                    className="input mt-2 max-w-xs"
                                    placeholder="Owner user ID"
                                    value={form.data.cuecs[cuec.id]?.owner_id ?? ''}
                                    onChange={(event) => form.setData('cuecs', {
                                        ...form.data.cuecs,
                                        [cuec.id]: { owner_id: event.target.value },
                                    })}
                                />
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {can.confirm && (
                <div className="mt-6 border-t border-gray-100 pt-4">
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>
                        Apply the selected proposals
                    </button>
                </div>
            )}
        </form>
    );
}

function PendingList({ title, note, items }) {
    return (
        <div className="mt-5">
            <h4 className="text-sm font-semibold text-gray-900">{title}</h4>
            <p className="mt-0.5 text-xs text-gray-500">{note}</p>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700">
                {items.map((item) => <li key={item.key}>{item.text}</li>)}
            </ul>
        </div>
    );
}

/* ------------------------------------------------------------------ */

/**
 * The AC-16 form.
 *
 * Deliberately a JSON textarea rather than a bespoke form per extractor. A
 * per-type form for eight document types is real work that belongs with the
 * screens for those types, and shipping a half-built one would be worse than
 * being plain: this records exactly the same field set through exactly the
 * same confirmer as the machine path, and the shape is documented above it.
 */
function ManualEntryDialog({ document, onClose }) {
    const form = useForm({
        extractor: document.extractor ?? 'generic',
        fields: '{\n}',
    });

    const submit = (event) => {
        event.preventDefault();

        let parsed;
        try {
            parsed = JSON.parse(form.data.fields);
        } catch (error) {
            form.setError('fields', 'That is not valid JSON.');
            return;
        }

        form.transform((data) => ({ extractor: data.extractor, fields: parsed }))
            .post(route('tprm.documents.manual', document.uuid), { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">Enter the details by hand</h2>
                <p className="mt-1 text-sm text-gray-600">
                    These go through the same path a machine reading would, so the register ends up in exactly
                    the same state.
                </p>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Document kind</span>
                    <input
                        type="text"
                        className="input mt-1"
                        value={form.data.extractor}
                        onChange={(event) => form.setData('extractor', event.target.value)}
                    />
                </label>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">Fields</span>
                    <textarea
                        rows={14}
                        className="input mt-1 font-mono text-xs"
                        value={form.data.fields}
                        onChange={(event) => form.setData('fields', event.target.value)}
                    />
                    {form.errors.fields && <p className="mt-1 text-xs text-red-600">{form.errors.fields}</p>}
                </label>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Record</button>
                </div>
            </form>
        </div>
    );
}

function renderValue(value) {
    if (value === null || value === undefined) {
        // "The document does not say" is an answer, and it prints as one
        // rather than as an empty cell a reader takes for a rendering bug.
        return <span className="text-gray-400">not stated</span>;
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? <span className="text-gray-400">none</span>
            : value.map((entry) => (typeof entry === 'object' ? JSON.stringify(entry) : String(entry))).join(', ');
    }

    if (typeof value === 'object') {
        return <span className="font-mono text-xs">{JSON.stringify(value)}</span>;
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}
