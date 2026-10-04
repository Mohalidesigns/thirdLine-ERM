import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { FormSection, FormField, FormActions } from '@thirdline/ui';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Register or edit a third party (FR-TPR-01).
 *
 * THE DUPLICATE PANEL IS THE INTERESTING PART. FR-TPR-02 requires that a
 * near-match presents merge candidates rather than silently blocking, so the
 * server returns the candidate list in a flash and the form re-renders with it
 * plus a confirm button. Blocking would be wrong on the merits — "Interlink
 * Systems Ltd" and "Interlink Systems Nigeria Ltd" are routinely two real
 * companies — and would teach people to misspell names to get past it, which
 * corrupts the register far more than the duplicate would.
 */
export default function Form({ thirdParty, options = {} }) {
    const { flash = {} } = usePage().props;
    const candidates = flash.duplicateCandidates ?? [];
    const editing = Boolean(thirdParty);

    const { data, setData, post, put, processing, errors } = useForm({
        legal_name: thirdParty?.legal_name ?? '',
        trading_name: thirdParty?.trading_name ?? '',
        registration_number: thirdParty?.registration_number ?? '',
        tax_id: thirdParty?.tax_id ?? '',
        lei: thirdParty?.lei ?? '',
        entity_type: thirdParty?.entity_type ?? '',
        country_of_incorporation: thirdParty?.country_of_incorporation ?? 'NG',
        country_of_hq: thirdParty?.country_of_hq ?? '',
        website: thirdParty?.website ?? '',
        year_established: thirdParty?.year_established ?? '',
        category_id: thirdParty?.category_id ?? '',
        ultimate_parent_id: thirdParty?.ultimate_parent_id ?? '',
        is_intra_group: thirdParty?.is_intra_group ?? false,
        relationship_owner_id: thirdParty?.relationship_owner_id ?? '',
        oversight_owner_id: thirdParty?.oversight_owner_id ?? '',
        status: thirdParty?.status ?? 'prospect',
        notes: thirdParty?.notes ?? '',
        accept_duplicate: false,
    });

    const submit = (acceptDuplicate) => (event) => {
        event.preventDefault();

        if (editing) {
            put(tryRoute('tprm.third-parties.update', thirdParty.uuid));
            return;
        }

        // Inertia's `data` is assigned after options are spread, and setData is
        // asynchronous — standard §9. `transform` is the only way to inject a
        // per-submit value and have it actually be sent.
        post(tryRoute('tprm.third-parties.store'), {
            transform: (payload) => ({ ...payload, accept_duplicate: acceptDuplicate }),
        });
    };

    return (
        <AppLayout title={editing ? 'Edit third party' : 'Register a third party'}>
            <Head title={editing ? 'Edit third party' : 'Register a third party'} />

            <PageHeader
                title={editing ? `Edit ${thirdParty.legal_name}` : 'Register a third party'}
                subtitle="Master data for the legal entity. Risk is assessed on its engagements, not here."
            />

            {candidates.length > 0 && (
                <div className="card mb-6 border-l-4 border-amber-400 p-5">
                    <h3 className="text-sm font-semibold text-amber-900">
                        {candidates.length} possible duplicate{candidates.length === 1 ? '' : 's'} found
                    </h3>
                    <p className="mt-1 text-xs text-amber-800">
                        Nothing has been saved. Open a candidate to check it, or confirm that this is a
                        genuinely different company and continue.
                    </p>
                    <ul className="mt-3 divide-y divide-amber-100">
                        {candidates.map((c) => (
                            <li key={c.id} className="flex items-center justify-between py-2 text-sm">
                                <span>
                                    <a href={c.url} className="font-medium text-blue-700 hover:underline">{c.legal_name}</a>
                                    {c.registration_number && (
                                        <span className="ml-2 font-mono text-xs text-gray-500">RC {c.registration_number}</span>
                                    )}
                                </span>
                                <span className="flex items-center gap-2 text-xs">
                                    <span className={`rounded px-1.5 py-0.5 font-medium ${
                                        c.kind === 'exact' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'
                                    }`}>
                                        {c.kind === 'exact' ? `Same ${c.on}` : `${c.confidence}% name match`}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    <button
                        type="button"
                        onClick={submit(true)}
                        disabled={processing}
                        className="btn-secondary mt-4 text-sm"
                    >
                        These are different companies — register anyway
                    </button>
                </div>
            )}

            <form onSubmit={submit(false)} className="form-page">
                <FormSection title="Identity">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Legal name" required error={errors.legal_name} htmlFor="legal_name">
                            <input id="legal_name" type="text" value={data.legal_name}
                                onChange={(e) => setData('legal_name', e.target.value)}
                                className="form-input" placeholder="e.g., Interlink Systems Ltd" />
                        </FormField>
                        <FormField label="Trading name" error={errors.trading_name} htmlFor="trading_name">
                            <input id="trading_name" type="text" value={data.trading_name}
                                onChange={(e) => setData('trading_name', e.target.value)} className="form-input" />
                        </FormField>
                        <FormField label="RC number" hint="CAC registration number" error={errors.registration_number} htmlFor="registration_number">
                            <input id="registration_number" type="text" value={data.registration_number}
                                onChange={(e) => setData('registration_number', e.target.value)} className="form-input" />
                        </FormField>
                        <FormField label="TIN" error={errors.tax_id} htmlFor="tax_id">
                            <input id="tax_id" type="text" value={data.tax_id}
                                onChange={(e) => setData('tax_id', e.target.value)} className="form-input" />
                        </FormField>
                        <FormField label="LEI" hint="20 characters, if the vendor has one" error={errors.lei} htmlFor="lei">
                            <input id="lei" type="text" value={data.lei}
                                onChange={(e) => setData('lei', e.target.value)} className="form-input" />
                        </FormField>
                        <FormField label="Entity type" error={errors.entity_type} htmlFor="entity_type">
                            <select id="entity_type" value={data.entity_type}
                                onChange={(e) => setData('entity_type', e.target.value)} className="form-select">
                                <option value="">Select type...</option>
                                {(options.entityTypes ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Country of incorporation" hint="ISO 3166 two-letter code" error={errors.country_of_incorporation} htmlFor="country_of_incorporation">
                            <input id="country_of_incorporation" type="text" value={data.country_of_incorporation}
                                onChange={(e) => setData('country_of_incorporation', e.target.value.toUpperCase())}
                                className="form-input" />
                        </FormField>
                        <FormField label="Country of HQ" error={errors.country_of_hq} htmlFor="country_of_hq">
                            <input id="country_of_hq" type="text" value={data.country_of_hq}
                                onChange={(e) => setData('country_of_hq', e.target.value.toUpperCase())}
                                className="form-input" />
                        </FormField>
                        <FormField label="Website" error={errors.website} htmlFor="website">
                            <input id="website" type="text" value={data.website}
                                onChange={(e) => setData('website', e.target.value)} className="form-input" placeholder="https://" />
                        </FormField>
                        <FormField label="Year established" error={errors.year_established} htmlFor="year_established">
                            <input id="year_established" type="number" value={data.year_established}
                                onChange={(e) => setData('year_established', e.target.value)} className="form-input" />
                        </FormField>
                    </div>
                </FormSection>

                <FormSection title="Classification and ownership">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Category" error={errors.category_id} htmlFor="category_id">
                            <select id="category_id" value={data.category_id}
                                onChange={(e) => setData('category_id', e.target.value)} className="form-select">
                                <option value="">Select...</option>
                                {(options.categories ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Ultimate parent" error={errors.ultimate_parent_id} htmlFor="ultimate_parent_id">
                            <select id="ultimate_parent_id" value={data.ultimate_parent_id}
                                onChange={(e) => setData('ultimate_parent_id', e.target.value)} className="form-select">
                                <option value="">Select...</option>
                                {(options.parents ?? []).map((p) => <option key={p.id} value={p.id}>{p.legal_name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Status" error={errors.status} htmlFor="status">
                            <select id="status" value={data.status}
                                onChange={(e) => setData('status', e.target.value)} className="form-select">
                                {(options.statuses ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </FormField>
                        <div className="flex items-end pb-2">
                            <label className="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" checked={data.is_intra_group}
                                    onChange={(e) => setData('is_intra_group', e.target.checked)} className="form-checkbox" />
                                Intra-group arrangement
                            </label>
                        </div>
                    </div>
                </FormSection>

                <FormSection title="Ownership of the relationship" description="Neither may be vacant while the third party is active (FR-TPR-06).">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <FormField label="Relationship owner" error={errors.relationship_owner_id} htmlFor="relationship_owner_id">
                            <select id="relationship_owner_id" value={data.relationship_owner_id}
                                onChange={(e) => setData('relationship_owner_id', e.target.value)} className="form-select">
                                <option value="">Select...</option>
                                {(options.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Oversight owner" hint="Risk or compliance" error={errors.oversight_owner_id} htmlFor="oversight_owner_id">
                            <select id="oversight_owner_id" value={data.oversight_owner_id}
                                onChange={(e) => setData('oversight_owner_id', e.target.value)} className="form-select">
                                <option value="">Select...</option>
                                {(options.users ?? []).map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </FormField>
                    </div>
                </FormSection>

                <FormSection title="Notes" badge="Optional">
                    <FormField error={errors.notes} htmlFor="notes">
                        <textarea id="notes" aria-label="Notes" rows="3" value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)} className="form-textarea" />
                    </FormField>
                </FormSection>

                <FormActions
                    submitLabel={editing ? 'Save changes' : 'Register'}
                    processingLabel="Saving..."
                    processing={processing}
                    cancelHref={tryRoute('tprm.third-parties.index')}
                />
            </form>
        </AppLayout>
    );
}
