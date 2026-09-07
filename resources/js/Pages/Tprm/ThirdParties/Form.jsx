import { Head, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputError from '@thirdline/ui/Components/InputError';
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

            <form onSubmit={submit(false)} className="space-y-6">
                <div className="card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-gray-900">Identity</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Text label="Legal name" required value={data.legal_name} onChange={(v) => setData('legal_name', v)} error={errors.legal_name} />
                        <Text label="Trading name" value={data.trading_name} onChange={(v) => setData('trading_name', v)} error={errors.trading_name} />
                        <Text label="RC number" hint="CAC registration number" value={data.registration_number} onChange={(v) => setData('registration_number', v)} error={errors.registration_number} />
                        <Text label="TIN" value={data.tax_id} onChange={(v) => setData('tax_id', v)} error={errors.tax_id} />
                        <Text label="LEI" hint="20 characters, if the vendor has one" value={data.lei} onChange={(v) => setData('lei', v)} error={errors.lei} />
                        <Select label="Entity type" value={data.entity_type} onChange={(v) => setData('entity_type', v)} error={errors.entity_type} options={options.entityTypes ?? []} />
                        <Text label="Country of incorporation" hint="ISO 3166 two-letter code" value={data.country_of_incorporation} onChange={(v) => setData('country_of_incorporation', v.toUpperCase())} error={errors.country_of_incorporation} />
                        <Text label="Country of HQ" value={data.country_of_hq} onChange={(v) => setData('country_of_hq', v.toUpperCase())} error={errors.country_of_hq} />
                        <Text label="Website" value={data.website} onChange={(v) => setData('website', v)} error={errors.website} />
                        <Text label="Year established" type="number" value={data.year_established} onChange={(v) => setData('year_established', v)} error={errors.year_established} />
                    </div>
                </div>

                <div className="card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-gray-900">Classification and ownership</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Select label="Category" value={data.category_id} onChange={(v) => setData('category_id', v)} error={errors.category_id}
                            options={(options.categories ?? []).map((c) => ({ value: c.id, label: c.name }))} />
                        <Select label="Ultimate parent" value={data.ultimate_parent_id} onChange={(v) => setData('ultimate_parent_id', v)} error={errors.ultimate_parent_id}
                            options={(options.parents ?? []).map((p) => ({ value: p.id, label: p.legal_name }))} />
                        <Select label="Status" value={data.status} onChange={(v) => setData('status', v)} error={errors.status} options={options.statuses ?? []} />
                        <label className="flex items-center gap-2 self-end pb-2 text-sm">
                            <input type="checkbox" checked={data.is_intra_group} onChange={(e) => setData('is_intra_group', e.target.checked)} className="rounded border-gray-300" />
                            Intra-group arrangement
                        </label>
                    </div>
                </div>

                <div className="card p-5">
                    <h3 className="mb-1 text-sm font-semibold text-gray-900">Ownership of the relationship</h3>
                    <p className="mb-4 text-xs text-gray-500">
                        Neither may be vacant while the third party is active (FR-TPR-06).
                    </p>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <Select label="Relationship owner" value={data.relationship_owner_id} onChange={(v) => setData('relationship_owner_id', v)} error={errors.relationship_owner_id}
                            options={(options.users ?? []).map((u) => ({ value: u.id, label: u.name }))} />
                        <Select label="Oversight owner" hint="Risk or compliance" value={data.oversight_owner_id} onChange={(v) => setData('oversight_owner_id', v)} error={errors.oversight_owner_id}
                            options={(options.users ?? []).map((u) => ({ value: u.id, label: u.name }))} />
                    </div>
                </div>

                <div className="card p-5">
                    <label className="block text-sm font-medium text-gray-700">Notes</label>
                    <textarea rows="3" value={data.notes} onChange={(e) => setData('notes', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                    <InputError message={errors.notes} className="mt-1" />
                </div>

                <div className="flex items-center justify-end gap-3">
                    <a href={tryRoute('tprm.third-parties.index')} className="btn-secondary text-sm">Cancel</a>
                    <button type="submit" disabled={processing} className="btn-primary text-sm">
                        {editing ? 'Save changes' : 'Register'}
                    </button>
                </div>
            </form>
        </AppLayout>
    );
}

function Text({ label, value, onChange, error, hint, required = false, type = 'text' }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700">
                {label}{required && <span className="ml-0.5 text-red-600">*</span>}
            </label>
            {hint && <p className="text-xs text-gray-500">{hint}</p>}
            <input type={type} value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
            <InputError message={error} className="mt-1" />
        </div>
    );
}

function Select({ label, value, onChange, error, options = [], hint }) {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-700">{label}</label>
            {hint && <p className="text-xs text-gray-500">{hint}</p>}
            <select value={value ?? ''} onChange={(e) => onChange(e.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">Select…</option>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}
