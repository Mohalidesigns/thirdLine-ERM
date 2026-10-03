import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import { FormSection, FormField, FormActions } from '@thirdline/ui';

/**
 * TPRM programme settings — the figures the module cannot ship a default for.
 *
 * THE PAGE LEADS WITH WHAT IS SWITCHED OFF. An empty settings form tells a
 * user nothing about why they are on it; this one says, before any field, that
 * the CBN materiality test cannot run without a shareholders' funds figure and
 * that incidents are therefore reported as undetermined. That is the reason to
 * fill the form in.
 *
 * NO FIELD IS PRE-FILLED WITH A GUESS. An LEI is issued by a registration
 * authority; a competent authority is a fact about the institution's licence.
 * Both are blank until somebody who knows types them.
 */
export default function Programme({ settings = {}, consequences = {}, updatedBy, updatedAt }) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        lei: settings.lei ?? '',
        country: settings.country ?? '',
        competent_authority: settings.competent_authority ?? '',
        reporting_currency: settings.reporting_currency ?? '',
        shareholders_funds: settings.shareholders_funds ?? '',
        shareholders_funds_currency: settings.shareholders_funds_currency ?? '',
        shareholders_funds_as_at: settings.shareholders_funds_as_at ?? '',
        regulatory_contact_name: settings.regulatory_contact_name ?? '',
        regulatory_contact_title: settings.regulatory_contact_title ?? '',
    });

    const materiality = consequences.materiality ?? {};
    const missingRegisterFields = consequences.register_of_information?.missing ?? [];

    const submit = (event) => {
        event.preventDefault();
        put(route('tprm.settings.programme.update'), { preserveScroll: true });
    };

    return (
        <AppLayout title="TPRM programme settings">
            <Head title="TPRM programme settings" />

            <PageHeader
                title="TPRM programme settings"
                subtitle="Four facts about this institution that the module cannot derive, and one figure that decides when an incident becomes reportable."
            />

            {!materiality.available && (
                <div className="mb-4 rounded border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <strong>The CBN materiality test cannot run.</strong> Without a shareholders&rsquo; funds
                    figure the {materiality.percentage}% limb of the cyber-incident definition is uncomputable,
                    so an incident whose only reportable limb is financial loss is recorded as{' '}
                    <em>undetermined</em> rather than as not reportable. That is the honest answer and it is not
                    a useful one.
                </div>
            )}

            {materiality.available && (
                <div className="mb-4 rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                    <strong>Materiality threshold: {materiality.threshold}</strong> {settings.shareholders_funds_currency}.
                    A third-party incident whose loss exceeds this is CBN-reportable on materiality alone.
                    {materiality.age_months !== null && materiality.age_months > 18 && (
                        <> The basis figure is {materiality.age_months} months old and should be refreshed.</>
                    )}
                </div>
            )}

            {missingRegisterFields.length > 0 && (
                <div className="mb-6 rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>The Register of Information prints &ldquo;Not set&rdquo; for:</strong>{' '}
                    {missingRegisterFields.join(', ')}. DORA RT.01.01 identifies the maintaining entity by these,
                    and none of them can be derived from a CBN institution code or an RC number.
                </div>
            )}

            <form onSubmit={submit} className="form-page">
                <FormSection
                    title="Institution identity"
                    description="Used by DORA RT.01.01. Leave a field blank rather than entering a partial value."
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field
                            label="Legal Entity Identifier (LEI)"
                            hint="20 characters, issued by a Local Operating Unit"
                            value={data.lei}
                            error={errors.lei}
                            onChange={(value) => setData('lei', value.toUpperCase())}
                            maxLength={20}
                        />
                        <Field
                            label="Country"
                            hint="ISO 3166-1 alpha-2, e.g. NG"
                            value={data.country}
                            error={errors.country}
                            onChange={(value) => setData('country', value.toUpperCase())}
                            maxLength={2}
                        />
                        <Field
                            label="Competent authority"
                            hint="The supervisor this register would be filed with"
                            value={data.competent_authority}
                            error={errors.competent_authority}
                            onChange={(value) => setData('competent_authority', value)}
                        />
                        <Field
                            label="Reporting currency"
                            hint="ISO 4217, e.g. NGN"
                            value={data.reporting_currency}
                            error={errors.reporting_currency}
                            onChange={(value) => setData('reporting_currency', value.toUpperCase())}
                            maxLength={3}
                        />
                    </div>
                </FormSection>

                <FormSection
                    title="Shareholders’ funds"
                    description={`The CBN cyber-incident definition turns on a loss exceeding ${materiality.percentage}% of this figure. The percentage is statutory; the figure is a property of the bank.`}
                >
                    <div className="grid gap-4 md:grid-cols-3">
                        <Field
                            label="Amount"
                            hint="In major units, as reported"
                            type="number"
                            step="0.01"
                            value={data.shareholders_funds}
                            error={errors.shareholders_funds}
                            onChange={(value) => setData('shareholders_funds', value)}
                        />
                        <Field
                            label="Currency"
                            hint="ISO 4217"
                            value={data.shareholders_funds_currency}
                            error={errors.shareholders_funds_currency}
                            onChange={(value) => setData('shareholders_funds_currency', value.toUpperCase())}
                            maxLength={3}
                        />
                        <Field
                            label="As at"
                            hint="The accounts this figure comes from"
                            type="date"
                            value={data.shareholders_funds_as_at}
                            error={errors.shareholders_funds_as_at}
                            onChange={(value) => setData('shareholders_funds_as_at', value)}
                        />
                    </div>
                </FormSection>

                <FormSection
                    title="Regulatory contact"
                    description="Named on notification drafts. Nothing is ever transmitted to a regulator from this product — a draft is assembled here and a person sends it."
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field
                            label="Name"
                            value={data.regulatory_contact_name}
                            error={errors.regulatory_contact_name}
                            onChange={(value) => setData('regulatory_contact_name', value)}
                        />
                        <Field
                            label="Title"
                            value={data.regulatory_contact_title}
                            error={errors.regulatory_contact_title}
                            onChange={(value) => setData('regulatory_contact_title', value)}
                        />
                    </div>
                </FormSection>

                <FormActions submitLabel="Save settings" processing={processing}>
                    <div className="mr-auto flex flex-col gap-0.5">
                        {recentlySuccessful && <span className="text-sm text-emerald-700">Saved.</span>}
                        {updatedBy && (
                            <span className="text-xs text-gray-500">
                                Last changed by {updatedBy} on {updatedAt}.
                            </span>
                        )}
                    </div>
                </FormActions>
            </form>
        </AppLayout>
    );
}

function Field({ label, hint, value, error, onChange, type = 'text', step, maxLength }) {
    return (
        <FormField label={label} hint={hint} error={error}>
            <input
                type={type}
                step={step}
                maxLength={maxLength}
                className="form-input"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormField>
    );
}
