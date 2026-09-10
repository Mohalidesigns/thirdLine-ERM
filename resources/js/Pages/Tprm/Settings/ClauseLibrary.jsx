import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The clause library settings screen.
 *
 * THE MODEL TEXT FIELD IS THE POINT OF THIS SCREEN, and the empty state is
 * deliberate. The shipped library carries citations, guidance and applicability
 * rules but no contract wording, because drafting contract language is a
 * lawyer's job and a plausible-looking clause pasted into a real agreement is
 * a liability rather than a feature. This is where a tenant's legal function
 * supplies its own, and the gap report says so in its place until they do.
 *
 * A SYSTEM CLAUSE SHOWS WHICH FIELDS ARE LOCKED AND WHY. A tenant able to
 * change a citation or turn off a blocking flag could make its own
 * audit-rights gap disappear — which is exactly the gap the regulator cares
 * about — so those fields are disabled with the reason next to them rather
 * than silently ignored on save.
 */
export default function ClauseLibrary({ clauses = [], summary = {}, can = {} }) {
    const [editing, setEditing] = useState(null);
    const [adding, setAdding] = useState(false);

    const categories = [...new Set(clauses.map((c) => c.category ?? 'other'))].sort();

    return (
        <AppLayout title="Clause library">
            <Head title="Clause library" />

            <PageHeader
                title="Clause library"
                subtitle="The terms a contract has to contain, where they come from, and which of them stop an engagement going live."
                actions={can.manage ? (
                    <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}>
                        Add a clause
                    </button>
                ) : null}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Tile label="Clauses" value={summary.total ?? 0} hint="in your library" />
                <Tile label="Block activation" value={summary.blocking ?? 0} hint="conditions of going live" />
                <Tile label="Shipped with the product" value={summary.system_owned ?? 0} hint="cannot be deleted, only waived" />
                <Tile
                    label="No model text yet"
                    value={summary.without_model_text ?? 0}
                    hint="your legal function supplies the wording"
                    tone={summary.without_model_text ? 'warn' : null}
                />
            </div>

            {categories.map((category) => (
                <div key={category} className="card mb-4">
                    <div className="border-b border-gray-100 px-5 py-3">
                        <h3 className="text-sm font-semibold capitalize text-gray-900">
                            {String(category).replace('_', ' ')}
                        </h3>
                    </div>
                    <div className="divide-y divide-gray-100">
                        {clauses.filter((c) => (c.category ?? 'other') === category).map((clause) => (
                            <ClauseRow
                                key={clause.id}
                                clause={clause}
                                can={can}
                                onEdit={() => setEditing(clause)}
                            />
                        ))}
                    </div>
                </div>
            ))}

            {editing && <ClauseDialog clause={editing} onClose={() => setEditing(null)} />}
            {adding && <ClauseDialog clause={null} onClose={() => setAdding(false)} />}
        </AppLayout>
    );
}

function Tile({ label, value, hint, tone }) {
    return (
        <div className="card p-4">
            <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold ${tone === 'warn' ? 'text-amber-700' : 'text-gray-900'}`}>
                {value}
            </p>
            <p className="mt-0.5 text-xs text-gray-500">{hint}</p>
        </div>
    );
}

function ClauseRow({ clause, can, onEdit }) {
    return (
        <div className="p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-sm font-semibold text-gray-900">
                        {clause.code} — {clause.title}
                        {clause.is_blocking && (
                            <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                Blocks activation
                            </span>
                        )}
                        {clause.is_system_owned && (
                            <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                Shipped
                            </span>
                        )}
                    </p>
                    {clause.citation && (
                        <p className="mt-0.5 text-xs text-gray-500">
                            {clause.citation}{clause.regulatory_source ? ` — ${clause.regulatory_source}` : ''}
                        </p>
                    )}
                </div>

                {can.manage && (
                    <div className="flex shrink-0 gap-2">
                        <button type="button" className="btn btn-secondary text-xs" onClick={onEdit}>
                            {clause.is_system_owned ? 'Edit wording' : 'Edit'}
                        </button>
                        {!clause.is_system_owned && (
                            <button
                                type="button"
                                className="btn btn-secondary text-xs"
                                onClick={() => router.delete(route('tprm.clauses.destroy', clause.id))}
                            >
                                Remove
                            </button>
                        )}
                    </div>
                )}
            </div>

            {clause.guidance && <p className="mt-2 text-sm text-gray-600">{clause.guidance}</p>}

            <p className="mt-2 text-xs text-gray-500">
                {clause.applies_always
                    ? 'Applies to every engagement.'
                    : 'Applies conditionally, from the engagement’s own attributes.'}
                {' '}
                {clause.has_model_text
                    ? 'Model wording supplied.'
                    : 'No model wording yet — the gap report asks for the obligation in plain words instead.'}
            </p>

            {clause.obligations.length > 0 && (
                <div className="mt-3 rounded bg-gray-50 p-3">
                    <p className="text-xs font-medium text-gray-600">
                        When this clause is present, it puts these on the obligation register:
                    </p>
                    <ul className="mt-1 space-y-0.5 text-xs text-gray-600">
                        {clause.obligations.map((obligation) => (
                            <li key={obligation.title}>
                                {obligation.obligor === 'entity' ? 'We' : 'They'} — {obligation.title}
                                {' '}({obligation.frequency.replace('_', ' ')})
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

function ClauseDialog({ clause, onClose }) {
    const editable = clause?.editable_fields ?? null;
    const locked = (field) => editable !== null && !editable.includes(field);

    const form = useForm({
        code: clause?.code ?? '',
        title: clause?.title ?? '',
        category: clause?.category ?? '',
        regulatory_source: clause?.regulatory_source ?? '',
        citation: clause?.citation ?? '',
        is_blocking: clause?.is_blocking ?? false,
        model_text: clause?.model_text ?? '',
        guidance: clause?.guidance ?? '',
    });

    const submit = (event) => {
        event.preventDefault();

        if (clause) {
            form.put(route('tprm.clauses.update', clause.id), { onSuccess: onClose });
        } else {
            form.post(route('tprm.clauses.store'), { onSuccess: onClose });
        }
    };

    return (
        <div className="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/30 p-6">
            <form onSubmit={submit} className="card w-full max-w-2xl p-6">
                <h2 className="text-base font-semibold text-gray-900">
                    {clause ? `${clause.code} — ${clause.title}` : 'Add a clause'}
                </h2>

                {clause?.is_system_owned && (
                    <p className="mt-2 rounded bg-gray-50 p-3 text-xs text-gray-600">
                        This clause ships with the product because a regulation requires it. Its wording is
                        yours to draft; its code, citation and blocking status are not — a library in which
                        those could be edited would let an audit-rights gap be defined out of existence.
                    </p>
                )}

                <div className="mt-5 space-y-4">
                    {!clause && (
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Code">
                                <input type="text" className="input" value={form.data.code}
                                    onChange={(e) => form.setData('code', e.target.value)} />
                            </Field>
                            <Field label="Category">
                                <input type="text" className="input" value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)} />
                            </Field>
                        </div>
                    )}

                    {!clause && (
                        <Field label="Title">
                            <input type="text" className="input" value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)} />
                        </Field>
                    )}

                    {!locked('citation') && (
                        <Field label="Citation">
                            <input type="text" className="input" value={form.data.citation}
                                onChange={(e) => form.setData('citation', e.target.value)} />
                        </Field>
                    )}

                    {!locked('is_blocking') && (
                        <label className="flex items-start gap-2">
                            <input type="checkbox" className="mt-1" checked={form.data.is_blocking}
                                onChange={(e) => form.setData('is_blocking', e.target.checked)} />
                            <span className="text-sm text-gray-700">
                                An engagement cannot be activated while this clause is missing.
                            </span>
                        </label>
                    )}

                    <Field label="Guidance">
                        <textarea rows={3} className="input" value={form.data.guidance}
                            onChange={(e) => form.setData('guidance', e.target.value)} />
                    </Field>

                    <Field label="Model wording">
                        <textarea rows={6} className="input font-mono text-xs" value={form.data.model_text}
                            onChange={(e) => form.setData('model_text', e.target.value)} />
                        <p className="mt-1 text-xs text-gray-500">
                            This is sent to the vendor on the gap report. It ships empty because contract
                            language is drafted by your legal function, not by us.
                        </p>
                    </Field>
                </div>

                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn btn-secondary" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>Save</button>
                </div>
            </form>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="text-sm font-medium text-gray-700">{label}</span>
            <div className="mt-1">{children}</div>
        </label>
    );
}
