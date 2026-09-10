import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The Questionnaire Builder (TRD §11).
 *
 * THE PUBLISH BLOCKER PANEL IS THE POINT OF THIS SCREEN. FR-ASM-05 stops a
 * template publishing while any question maps to no control, and the panel
 * turns that from an error an author meets once into a list they work through.
 * The wording matters: an author who reads it as an obstacle maps everything
 * to A.5.19 and the gate achieves nothing, so it says what the rule is for.
 *
 * The rule preview evaluates a visibility rule against a real engagement,
 * server-side, through the same evaluator that will scope the assessment — so
 * "this will only be asked of cross-border processors" is something the author
 * can see rather than hope.
 */
export default function Builder({ template, sections = [], publishBlockers = [], canPublish, facts = [], can = {} }) {
    const { flash = {} } = usePage().props;
    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);

    const runPreview = async (rule) => {
        setPreviewing(true);
        try {
            const response = await window.axios.post(tryRoute('tprm.templates.preview-rule'), { rule });
            setPreview({ rule, ...response.data });
        } finally {
            setPreviewing(false);
        }
    };

    return (
        <AppLayout title={template.name}>
            <Head title={template.name} />

            <PageHeader
                title={template.name}
                subtitle={
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-xs">{template.code} v{template.version}</span>
                        <span className="text-gray-300">·</span>
                        <span className="capitalize">{template.status}</span>
                        {template.is_system_pack && (
                            <>
                                <span className="text-gray-300">·</span>
                                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">
                                    Shipped — read only
                                </span>
                            </>
                        )}
                    </span>
                }
                actions={
                    <div className="flex items-center gap-2">
                        {can.clone && (
                            <button type="button"
                                onClick={() => router.post(tryRoute('tprm.templates.clone', template.id))}
                                className="btn-secondary text-sm">
                                {template.is_system_pack ? 'Customise a copy' : 'Duplicate'}
                            </button>
                        )}
                        {can.publish && template.status === 'draft' && (
                            <button type="button"
                                disabled={!canPublish}
                                title={canPublish ? undefined : 'Map every question to a control first.'}
                                onClick={() => router.post(tryRoute('tprm.templates.publish', template.id))}
                                className="btn-primary text-sm disabled:opacity-50">
                                Publish
                            </button>
                        )}
                    </div>
                }
            />

            {template.catalogue_status === 'partial' && (
                <div className="card mb-4 border-l-4 border-amber-400 p-4">
                    <p className="text-xs text-amber-900">{template.catalogue_note}</p>
                </div>
            )}

            {publishBlockers.length > 0 && (
                <div className="card mb-6 border-l-4 border-red-500 p-5">
                    <h3 className="text-sm font-semibold text-red-900">
                        {publishBlockers.length} question{publishBlockers.length === 1 ? '' : 's'} map to no control
                    </h3>
                    <p className="mt-1 text-xs text-red-800">
                        A questionnaire cannot be published until every question names a control it tests — in
                        ISO 27002, NIST 800-53, CSF 2.0, CCM or the Trust Services Criteria. This is not
                        bookkeeping: it is what keeps a questionnaire short enough that a vendor answers it
                        carefully. A question you cannot map to a control is usually a question worth deleting.
                    </p>
                    <ul className="mt-3 space-y-1.5">
                        {publishBlockers.map((q) => (
                            <li key={q.code} className="text-xs">
                                <span className="font-mono font-semibold text-red-900">{q.code}</span>
                                <span className="ml-2 text-red-800">{q.text}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {flash.unmappedQuestions?.length > 0 && (
                <div className="card mb-6 border-l-4 border-red-500 p-4 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {sections.map((section) => (
                        <div key={section.id} className="card p-5">
                            <div className="flex items-start justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900">{section.title}</h3>
                                    <p className="font-mono text-xs text-gray-500">{section.code}</p>
                                </div>
                                {section.domain_tag && (
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">
                                        {section.domain_tag}
                                    </span>
                                )}
                            </div>

                            {section.visibility_rule && (
                                <div className="mt-2 flex items-center gap-2">
                                    <span className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-800">
                                        Conditional
                                    </span>
                                    <button type="button" onClick={() => runPreview(section.visibility_rule)}
                                        className="text-[11px] text-blue-700 hover:underline">
                                        Preview the rule
                                    </button>
                                </div>
                            )}

                            <ul className="mt-4 divide-y divide-gray-100">
                                {section.questions.map((question) => (
                                    <li key={question.id} className="py-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex-1">
                                                <p className="text-sm text-gray-900">
                                                    <span className="mr-2 font-mono text-xs text-gray-400">{question.code}</span>
                                                    {question.text}
                                                </p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px]">
                                                    {question.controls.length > 0 ? (
                                                        question.controls.map((c) => (
                                                            <span key={c.id} className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-600">
                                                                {c.framework} {c.control_id}
                                                            </span>
                                                        ))
                                                    ) : (
                                                        <span className="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">
                                                            No control mapped
                                                        </span>
                                                    )}
                                                    <span className="text-gray-400">weight {question.risk_weight}</span>
                                                    {question.is_critical && (
                                                        <span className="rounded bg-red-100 px-1.5 py-0.5 font-medium text-red-800">
                                                            Critical
                                                        </span>
                                                    )}
                                                    {question.evidence_required && (
                                                        <span className="text-gray-500">evidence required</span>
                                                    )}
                                                </div>
                                            </div>
                                            {question.visibility_rule && (
                                                <button type="button" onClick={() => runPreview(question.visibility_rule)}
                                                    className="shrink-0 text-[11px] text-blue-700 hover:underline">
                                                    Preview rule
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="lg:col-span-1">
                    <div className="sticky top-6 space-y-4">
                        <div className="card p-5">
                            <h3 className="text-sm font-semibold text-gray-900">Rule preview</h3>
                            <p className="mt-1 text-xs text-gray-500">
                                Evaluated server-side by the same evaluator that scopes an assessment, so what you
                                see here is what a vendor will be asked.
                            </p>

                            {previewing && <p className="mt-3 text-xs text-gray-400">Evaluating…</p>}

                            {preview && !previewing && (
                                <div className="mt-3 space-y-3">
                                    <div className={`rounded p-2.5 text-xs ${
                                        preview.matches ? 'bg-green-50 text-green-900' : 'bg-gray-50 text-gray-700'
                                    }`}>
                                        {preview.matches
                                            ? 'The rule matches — the question would be asked.'
                                            : 'The rule does not match — the question would be skipped.'}
                                    </div>

                                    {preview.facts_used?.length > 0 && (
                                        <div>
                                            <h4 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                                Facts the rule reads
                                            </h4>
                                            <ul className="mt-1 space-y-1">
                                                {preview.facts_used.map((fact) => (
                                                    <li key={fact} className="flex items-center justify-between text-[11px]">
                                                        <span className="font-mono text-gray-600">{fact}</span>
                                                        <span className="text-gray-900">
                                                            {String(preview.context?.[fact] ?? '—')}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}

                                    {preview.unresolved_facts?.length > 0 && (
                                        <div className="rounded bg-amber-50 p-2.5 text-[11px] text-amber-900">
                                            <p className="font-medium">Could not be evaluated</p>
                                            <p className="mt-1">
                                                {preview.unresolved_facts.join(', ')} — the question would be asked
                                                anyway, so that missing data does not narrow the questionnaire.
                                            </p>
                                        </div>
                                    )}

                                    <pre className="overflow-x-auto rounded bg-gray-900 p-2 text-[10px] text-gray-100">
                                        {JSON.stringify(preview.rule, null, 2)}
                                    </pre>
                                </div>
                            )}

                            {!preview && !previewing && (
                                <p className="mt-3 text-xs text-gray-500">
                                    Choose “Preview rule” beside a conditional question or section.
                                </p>
                            )}
                        </div>

                        <div className="card p-5">
                            <h3 className="text-sm font-semibold text-gray-900">Facts a rule may use</h3>
                            <p className="mt-1 text-xs text-gray-500">
                                A whitelist. A rule is a stored, exportable object, so it may not name a
                                credential — and a fact renamed later stays a fact this list has promised to keep.
                            </p>
                            <ul className="mt-3 max-h-72 space-y-1 overflow-y-auto">
                                {facts.map((fact) => (
                                    <li key={fact.name} className="text-[11px]">
                                        <span className="font-mono text-gray-700">{fact.name}</span>
                                        <p className="text-gray-500">{fact.description}</p>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
