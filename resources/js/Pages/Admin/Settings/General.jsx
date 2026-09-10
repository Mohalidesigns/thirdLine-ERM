import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';

const TABS = [
    ['profile', 'Institution'],
    ['scoring', 'Risk scoring'],
    ['calculation', 'Calculation settings'],
    ['platform', 'Currency and access'],
];

const BAND_LABELS = {
    effective: 'Effective',
    mostly_effective: 'Mostly effective',
    partially_effective: 'Partially effective',
    ineffective: 'Ineffective',
    not_operating: 'Not operating',
};

const RATE_TYPE_LABELS = {
    cbn_official: 'CBN official',
    nafem: 'NAFEM',
    parallel: 'Parallel',
    internal: 'Internal',
    custom: 'Custom',
};

function Panel({ title, description, children, onSubmit, processing, submitLabel = 'Save changes' }) {
    return (
        <form onSubmit={onSubmit} className="bg-white rounded-b-xl border border-t-0 border-gray-200 p-6 space-y-6">
            <div>
                <h3 className="text-base font-semibold text-[#1A365D]">{title}</h3>
                {description && <p className="text-sm text-gray-500 mt-1 max-w-2xl">{description}</p>}
            </div>

            {children}

            <div className="flex justify-end pt-2 border-t border-gray-100">
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
            </div>
        </form>
    );
}

function Field({ name, label, hint, form, type = 'text', ...props }) {
    return (
        <div>
            <InputLabel htmlFor={name} value={label} />
            <TextInput
                id={name}
                type={type}
                className="mt-1 block w-full"
                value={form.data[name] ?? ''}
                onChange={(e) => form.setData(name, e.target.value)}
                {...props}
            />
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
            <InputError message={form.errors[name]} className="mt-1" />
        </div>
    );
}

/**
 * Organisation settings (migration Phase 6.2).
 *
 * The Blade screen carried five panels. Two of them — regulatory thresholds
 * and notification preferences — wrote ten keys into `organizations.settings`
 * that no service, job, command or template ever read. They are gone, and the
 * "Calculation settings" and "Currency and access" panels here hold the keys
 * that do have readers. The reasoning is in
 * docs/migration/phase-6-notes/organisation-and-sso.md.
 */
export default function General({ profile, riskSettings, calculation, platform, options, matrix }) {
    const [tab, setTab] = useState('profile');
    const { flash } = usePage().props;

    const profileForm = useForm({ ...profile });
    const riskForm = useForm({ ...riskSettings });
    const settingsForm = useForm({
        control_effectiveness: { ...calculation.control_effectiveness },
        regulatory_reportable_threshold_ngn: calculation.regulatory_reportable_threshold_ngn,
        reporting_currency: platform.reporting_currency,
        default_fx_rate_type: platform.default_fx_rate_type,
        mfa_required_roles: platform.mfa_required_roles,
    });

    const submit = (form, routeName) => (event) => {
        event.preventDefault();
        form.put(route(routeName), { preserveScroll: true });
    };

    const toggleMfaRole = (role) =>
        settingsForm.setData(
            'mfa_required_roles',
            settingsForm.data.mfa_required_roles.includes(role)
                ? settingsForm.data.mfa_required_roles.filter((r) => r !== role)
                : [...settingsForm.data.mfa_required_roles, role],
        );

    return (
        <AuthenticatedLayout title="Organization Settings">
            <Head title="Organization Settings" />

            <PageHeader
                title="Organization Settings"
                subtitle="Institution details, the scoring matrix, and the values the calculations read"
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}

            <div className="bg-white rounded-t-xl border-b border-gray-200">
                <div className="flex gap-8 px-6 overflow-x-auto">
                    {TABS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`border-b-2 py-4 text-sm whitespace-nowrap transition-colors ${
                                tab === key
                                    ? 'border-[#1A365D] text-[#1A365D] font-semibold'
                                    : 'border-transparent text-gray-600 hover:text-[#1A365D]'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            {tab === 'profile' && (
                <Panel
                    title="Institution details"
                    description="Printed on generated documents and board packs."
                    onSubmit={submit(profileForm, 'admin.settings.profile')}
                    processing={profileForm.processing}
                >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl">
                        <div className="md:col-span-2">
                            <Field name="org_name" label="Organization name" form={profileForm} required />
                        </div>
                        <Field name="org_code" label="Organization code" form={profileForm} required />
                        <Field name="industry" label="Industry" form={profileForm} required />
                        <Field name="country" label="Country" form={profileForm} required />
                        <Field
                            name="regulatory_framework"
                            label="Regulatory framework"
                            hint="e.g. Basel III, CBN ORMS"
                            form={profileForm}
                            required
                        />
                    </div>
                </Panel>
            )}

            {tab === 'scoring' && (
                <Panel
                    title="Risk scoring"
                    description={`The matrix is currently ${matrix.rows}×${matrix.cols}. Changing its size resizes the organisation's default scoring profile and re-rates every risk in the register against the new bands.`}
                    onSubmit={submit(riskForm, 'admin.settings.risk')}
                    processing={riskForm.processing}
                >
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-3xl">
                        <Field name="scoring_methodology" label="Scoring methodology" form={riskForm} required />
                        <div>
                            <InputLabel htmlFor="review_frequency" value="Review frequency" />
                            <TextInput
                                id="review_frequency"
                                className="mt-1 block w-full"
                                value={riskForm.data.review_frequency ?? ''}
                                onChange={(e) => riskForm.setData('review_frequency', e.target.value)}
                                required
                            />
                            <InputError message={riskForm.errors.review_frequency} className="mt-1" />
                        </div>
                        <Field
                            name="probability_scale"
                            label="Likelihood scale"
                            type="number"
                            min="3"
                            max="10"
                            hint="Between 3 and 10. Below 3 a matrix cannot distinguish anything; above 10 it is unreadable."
                            form={riskForm}
                            required
                        />
                        <Field
                            name="impact_scale"
                            label="Impact scale"
                            type="number"
                            min="3"
                            max="10"
                            form={riskForm}
                            required
                        />
                        <div className="md:col-span-2">
                            <InputLabel htmlFor="calculation_method" value="Impact aggregation" />
                            <select
                                id="calculation_method"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={riskForm.data.calculation_method}
                                onChange={(e) => riskForm.setData('calculation_method', e.target.value)}
                            >
                                <option value="max">Worst dimension wins</option>
                                <option value="average">Mean of the scored dimensions</option>
                                <option value="weighted">Weighted mean</option>
                                <option value="worst_two">Mean of the two highest</option>
                            </select>
                            <p className="text-xs text-gray-500 mt-1">
                                How the five impact dimensions collapse into one score. Saved onto the scoring profile,
                                which is what the calculations read.
                            </p>
                            <InputError message={riskForm.errors.calculation_method} className="mt-1" />
                        </div>
                    </div>

                    {matrix.bands.length > 0 && (
                        <div className="max-w-3xl">
                            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                                Current rating bands
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {matrix.bands.map((band) => (
                                    <span
                                        key={band.label}
                                        className="px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700"
                                    >
                                        {band.label} {band.min}–{band.max}
                                    </span>
                                ))}
                            </div>
                        </div>
                    )}
                </Panel>
            )}

            {tab === 'calculation' && (
                <Panel
                    title="Calculation settings"
                    description="What a control contributes when it has no measured effectiveness of its own, and the loss above which an event is flagged for regulatory reporting. Both are read on every calculation."
                    onSubmit={submit(settingsForm, 'admin.settings.organization')}
                    processing={settingsForm.processing}
                >
                    <div className="max-w-3xl space-y-6">
                        <div>
                            <p className="text-sm font-semibold text-gray-700 mb-3">Control effectiveness bands</p>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                {options.effectivenessBands.map((band) => (
                                    <div key={band}>
                                        <InputLabel htmlFor={`band-${band}`} value={BAND_LABELS[band] ?? band} />
                                        <TextInput
                                            id={`band-${band}`}
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="1"
                                            className="mt-1 block w-full"
                                            value={settingsForm.data.control_effectiveness[band] ?? ''}
                                            onChange={(e) =>
                                                settingsForm.setData('control_effectiveness', {
                                                    ...settingsForm.data.control_effectiveness,
                                                    [band]: e.target.value,
                                                })
                                            }
                                            required
                                        />
                                        <InputError
                                            message={settingsForm.errors[`control_effectiveness.${band}`]}
                                            className="mt-1"
                                        />
                                    </div>
                                ))}
                            </div>
                            <p className="text-xs text-gray-500 mt-2">
                                Percentages. The platform's defaults stop short of 100 and of 0 deliberately — no
                                control is perfect, and one that contributes nothing is indistinguishable from having no
                                control at all.
                            </p>
                        </div>

                        <div className="md:w-1/2">
                            <InputLabel
                                htmlFor="regulatory_reportable_threshold_ngn"
                                value="Regulatory reporting threshold (₦)"
                            />
                            <TextInput
                                id="regulatory_reportable_threshold_ngn"
                                type="number"
                                min="0"
                                step="1000"
                                className="mt-1 block w-full"
                                value={settingsForm.data.regulatory_reportable_threshold_ngn ?? ''}
                                onChange={(e) =>
                                    settingsForm.setData('regulatory_reportable_threshold_ngn', e.target.value)
                                }
                                required
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Gross loss at or above which an event is flagged reportable when the reporter has not
                                answered.
                            </p>
                            <InputError
                                message={settingsForm.errors.regulatory_reportable_threshold_ngn}
                                className="mt-1"
                            />
                        </div>
                    </div>
                </Panel>
            )}

            {tab === 'platform' && (
                <Panel
                    title="Currency and access"
                    description="The currency group roll-ups convert into, the rate they default to, and the roles that must carry multi-factor authentication."
                    onSubmit={submit(settingsForm, 'admin.settings.organization')}
                    processing={settingsForm.processing}
                >
                    <div className="max-w-3xl space-y-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <InputLabel htmlFor="reporting_currency" value="Reporting currency" />
                                <TextInput
                                    id="reporting_currency"
                                    className="mt-1 block w-full uppercase"
                                    maxLength={3}
                                    value={settingsForm.data.reporting_currency ?? ''}
                                    onChange={(e) =>
                                        settingsForm.setData('reporting_currency', e.target.value.toUpperCase())
                                    }
                                    required
                                />
                                <p className="text-xs text-gray-500 mt-1">Three-letter code, e.g. NGN.</p>
                                <InputError message={settingsForm.errors.reporting_currency} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="default_fx_rate_type" value="Default exchange rate" />
                                <select
                                    id="default_fx_rate_type"
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={settingsForm.data.default_fx_rate_type}
                                    onChange={(e) => settingsForm.setData('default_fx_rate_type', e.target.value)}
                                >
                                    {options.rateTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {RATE_TYPE_LABELS[type] ?? type}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={settingsForm.errors.default_fx_rate_type} className="mt-1" />
                            </div>
                        </div>

                        <div>
                            <p className="text-sm font-semibold text-gray-700 mb-1">
                                Roles that must use multi-factor authentication
                            </p>
                            <p className="text-xs text-gray-500 mb-3">
                                Anyone holding one of these is required to enrol before they can reach the product.
                            </p>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
                                {options.roles.map((role) => (
                                    <label
                                        key={role}
                                        className="flex items-center gap-2 p-3 rounded-lg bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={settingsForm.data.mfa_required_roles.includes(role)}
                                            onChange={() => toggleMfaRole(role)}
                                        />
                                        <span className="text-sm text-gray-700">{role}</span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={settingsForm.errors.mfa_required_roles} className="mt-2" />
                        </div>
                    </div>
                </Panel>
            )}
        </AuthenticatedLayout>
    );
}
