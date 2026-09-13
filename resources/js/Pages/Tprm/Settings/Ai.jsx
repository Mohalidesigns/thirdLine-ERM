import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * TPRM AI settings — `docs/tprm/screens/ai-settings.md`,
 * phase-11a-ai-contract.md §7.1-§7.2.
 *
 * THREE LAYERS, ALWAYS VISIBLE. An administrator who sees only "off" cannot
 * tell whether they turned it off or the deployment did, and those have
 * different remedies. The layer table below is never collapsed and never
 * behind a disclosure.
 *
 * THE MASTER SWITCH AND EVERY SERVICE SWITCH ARE NATIVE RADIO GROUPS, never a
 * checkbox — `ai_enabled` and each `ai_services.<key>` entry are tri-state
 * (on / off / "nobody has asked"), and a checkbox has no way to say the third
 * thing.
 *
 * `effective.master_layer` (nullable string, null when enabled) is read
 * exactly as each per-service row's own `layer` is — computed server-side by
 * `LlmGateway::availability()`'s resolution order, never re-derived here. An
 * earlier version of this file picked among `deployment.llm_enabled` /
 * `deployment.module_enabled` / `tenant.ai_enabled` in JS to invent this
 * value; that was rejected (contract §7.1, ruled 2026-09-11, frontend
 * deviation 1) because a JS fall-through cannot express `cap` or `breaker` —
 * it would keep naming `tenant_master` on a day the real reason is neither,
 * sending an administrator to change a switch that will not fix anything.
 */
export default function Ai({
    deployment = {},
    tenant = {},
    effective = {},
    breaker = {},
    month = {},
    stale_profile: staleProfile = null,
    updatedBy = null,
    updatedAt = null,
}) {
    const services = deployment.services ?? [];
    const profiles = deployment.profiles ?? [];

    const initialServiceChoices = {};
    services.forEach((row) => {
        if (row.implemented) {
            initialServiceChoices[row.key] = row.tenant_value;
        }
    });

    const { data, setData, put, processing, errors, recentlySuccessful, isDirty } = useForm({
        ai_enabled: tenant.ai_enabled ?? null,
        ai_services: initialServiceChoices,
        ai_endpoint_profile: tenant.ai_endpoint_profile ?? deployment.default_profile_key ?? '',
        ai_monthly_token_cap: tenant.ai_monthly_token_cap ?? '',
        ai_monthly_call_cap: tenant.ai_monthly_call_cap ?? '',
    });

    useEffect(() => {
        if (!isDirty) return undefined;

        const handler = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [isDirty]);

    const deploymentBlockedByLlm = !deployment.llm_enabled;
    const deploymentBlockedByModule = !deploymentBlockedByLlm && !deployment.module_enabled;
    const deploymentFullyOff = deploymentBlockedByLlm || deploymentBlockedByModule;
    const infraBannerNeeded = !deployment.cache_store_is_shared;

    const defaultProfile = profiles.find((profile) => profile.is_default) ?? null;

    const submit = (event) => {
        event.preventDefault();
        put(route('tprm.settings.ai.update'), { preserveScroll: true });
    };

    const setServiceChoice = (key, value) => {
        setData('ai_services', { ...data.ai_services, [key]: value });
    };

    return (
        <AppLayout title="TPRM AI settings">
            <Head title="TPRM AI settings" />

            <PageHeader
                title="TPRM AI settings"
                subtitle="Which AI services this tenant may use, which endpoint answers them, and the monthly usage limit. AI is never on by default — it stays off until a deployment operator and this tenant both say yes."
                actions={
                    <Link href={route('tprm.settings.ai.usage')} className="btn-secondary">
                        View usage report
                    </Link>
                }
            />

            {deploymentBlockedByLlm && (
                <Banner tone="red">
                    <strong>AI is switched off for this entire deployment.</strong> Nothing below this line can
                    turn it on — that requires an operator to enable <code>services.llm.enabled</code>.
                </Banner>
            )}
            {!deploymentBlockedByLlm && deploymentBlockedByModule && (
                <Banner tone="red">
                    <strong>AI is switched off for TPRM at the deployment level.</strong> Your tenant setting
                    below has no effect until an operator enables it for the module.
                </Banner>
            )}

            {infraBannerNeeded && (
                <Banner tone="amber">
                    This deployment&rsquo;s circuit breaker cannot share state across workers (the cache store is
                    not shared). A failing endpoint will not be protected the way this screen implies below —
                    every worker will keep retrying it independently.
                </Banner>
            )}

            {staleProfile && (
                <Banner tone="amber">
                    The endpoint profile this tenant was set to, <strong>{staleProfile}</strong>, is no longer
                    offered. This tenant is currently using the default profile,{' '}
                    {defaultProfile?.label ?? deployment.default_profile_key}, instead. Pick a current profile
                    below and save to clear this.
                </Banner>
            )}

            <form onSubmit={submit} className="space-y-6">
                <LayerTable
                    deployment={deployment}
                    tenant={tenant}
                    effective={effective}
                />

                <section className="card p-4">
                    <p className="mb-3 text-xs text-gray-500">
                        Whether this tenant may use AI at all. Every service below is still subject to its own
                        switch and to whatever the deployment allows.
                    </p>
                    <TriStateRadios
                        name="ai_enabled"
                        legend="Master switch"
                        hideLegend={false}
                        value={data.ai_enabled}
                        onChange={(value) => setData('ai_enabled', value)}
                        followLabel={`Follow the deployment default (currently ${
                            deployment.llm_enabled && deployment.module_enabled ? 'On' : 'Off'
                        })`}
                        onLabel="On for this tenant"
                        offLabel="Off for this tenant"
                    />
                    {errors.ai_enabled && (
                        <p role="alert" className="mt-2 text-xs text-red-600">{errors.ai_enabled}</p>
                    )}
                </section>

                <ServicesTable
                    services={services}
                    deploymentFullyOff={deploymentFullyOff}
                    choices={data.ai_services}
                    onChoice={setServiceChoice}
                    servicesError={errors.ai_services}
                />

                <section className="card p-4">
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium text-gray-700">Endpoint profile</span>
                        <select
                            className="w-full rounded border-gray-300 text-sm md:w-96"
                            value={data.ai_endpoint_profile}
                            onChange={(event) => setData('ai_endpoint_profile', event.target.value)}
                            aria-describedby={errors.ai_endpoint_profile ? 'ai-endpoint-profile-error' : undefined}
                        >
                            {profiles.map((profile) => (
                                <option key={profile.key} value={profile.key}>
                                    {profile.label}
                                    {profile.is_default ? ' (deployment default)' : ''}
                                    {profile.priced ? ' (metered)' : ''}
                                </option>
                            ))}
                        </select>
                        {errors.ai_endpoint_profile && (
                            <p id="ai-endpoint-profile-error" role="alert" className="mt-1 text-xs text-red-600">
                                {errors.ai_endpoint_profile}
                            </p>
                        )}
                    </label>
                </section>

                <UsageLimitSection
                    data={data}
                    setData={setData}
                    errors={errors}
                    month={month}
                />

                <BreakerCard breaker={breaker} deployment={deployment} effective={effective} profiles={profiles} />

                <div className="flex flex-wrap items-center gap-4">
                    <button type="submit" className="btn-primary" disabled={processing}>
                        {processing ? 'Saving…' : 'Save settings'}
                    </button>
                    <span aria-live="polite">
                        {recentlySuccessful && <span className="text-sm text-emerald-700">Saved.</span>}
                    </span>
                    <span className="text-xs text-gray-500">
                        {updatedAt
                            ? (updatedBy ? `Last changed by ${updatedBy} on ${updatedAt}.` : `Last changed on ${updatedAt}.`)
                            : 'Never changed.'}
                    </span>
                </div>
            </form>
        </AppLayout>
    );
}

/**
 * The layer text shared between the three-layer table's "effective master"
 * row and every service row's Effective cell. Keyed on `layer` values the
 * BACKEND computes (`LlmGateway`'s `Availability::$layer`, contract §2.2) —
 * this is a label lookup for a code the server already decided, not a
 * recomputation of the decision itself.
 */
const LAYER_TEXT = {
    deployment_llm: 'the deployment has AI switched off entirely',
    deployment_module: 'the deployment has AI switched off for TPRM',
    tenant_master: 'the tenant master switch above is off',
    deployment_service: 'blocked by deployment',
    tenant_service: 'deployment allows it, tenant said no',
    cap: 'the monthly usage cap has been reached',
    breaker: 'the circuit breaker is currently open for the resolved endpoint',
    endpoint: 'the resolved endpoint is unreachable',
};

function layerText(layer) {
    return LAYER_TEXT[layer] ?? 'blocked';
}

function toneClasses(tone) {
    switch (tone) {
        case 'red':
            return 'border-red-200 bg-red-50 text-red-900';
        case 'amber':
            return 'border-amber-200 bg-amber-50 text-amber-900';
        case 'emerald':
            return 'border-emerald-200 bg-emerald-50 text-emerald-900';
        default:
            return 'border-gray-200 bg-gray-50 text-gray-800';
    }
}

function Banner({ tone, children }) {
    return (
        <div className={`mb-4 rounded border p-4 text-sm ${toneClasses(tone)}`}>{children}</div>
    );
}

function Badge({ tone, children }) {
    const classes = {
        red: 'bg-red-100 text-red-800',
        amber: 'bg-amber-100 text-amber-800',
        emerald: 'bg-emerald-100 text-emerald-800',
        grey: 'bg-gray-100 text-gray-700',
    };

    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${classes[tone] ?? classes.grey}`}>
            {children}
        </span>
    );
}

function LayerTable({ deployment, tenant, effective }) {
    const followsCurrently = deployment.llm_enabled && deployment.module_enabled ? 'On' : 'Off';

    return (
        <section className="card overflow-hidden">
            <table className="w-full text-sm">
                <caption className="p-4 text-left text-sm font-semibold text-gray-800">
                    How AI is switched on for this tenant, by layer
                </caption>
                <thead>
                    <tr className="border-t border-gray-200 bg-gray-50">
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Layer</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">State</th>
                    </tr>
                </thead>
                <tbody>
                    <tr className="border-t border-gray-100">
                        <th scope="row" className="px-4 py-2 text-left font-normal text-gray-700">
                            Deployment — model available
                        </th>
                        <td className="px-4 py-2">
                            <Badge tone={deployment.llm_enabled ? 'emerald' : 'red'}>
                                {deployment.llm_enabled ? 'On' : 'Off'}
                            </Badge>
                        </td>
                    </tr>
                    <tr className="border-t border-gray-100">
                        <th scope="row" className="px-4 py-2 text-left font-normal text-gray-700">
                            Deployment — TPRM module
                        </th>
                        <td className="px-4 py-2">
                            <Badge tone={deployment.module_enabled ? 'emerald' : 'red'}>
                                {deployment.module_enabled ? 'On' : 'Off'}
                            </Badge>
                        </td>
                    </tr>
                    <tr className="border-t border-gray-100">
                        <th scope="row" className="px-4 py-2 text-left font-normal text-gray-700">
                            Tenant — master switch
                        </th>
                        <td className="px-4 py-2">
                            {tenant.ai_enabled === null ? (
                                <Badge tone="grey">Not set — follows deployment (currently {followsCurrently})</Badge>
                            ) : tenant.ai_enabled ? (
                                <Badge tone="emerald">On</Badge>
                            ) : (
                                <Badge tone="grey">Off</Badge>
                            )}
                        </td>
                    </tr>
                    <tr className="border-t border-gray-100">
                        <th scope="row" className="px-4 py-2 text-left font-normal text-gray-700">
                            Effective master
                        </th>
                        <td className="px-4 py-2">
                            {effective.enabled ? (
                                <Badge tone="emerald">On</Badge>
                            ) : (
                                <Badge tone="red">Off — {layerText(effective.master_layer)}</Badge>
                            )}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    );
}

/**
 * Every tri-state group is its own `<fieldset>` with a `<legend>` naming what
 * it controls — WCAG 1.3.1. `legend` is REQUIRED, not optional: three
 * identically-worded service radiogroups on one page ("Follow the deployment
 * default…" / "On for this tenant" / "Off for this tenant") are indistinguishable
 * to a screen-reader user tabbing through them unless each one announces its
 * own group name first. The master switch's own visible section heading
 * already names it for sighted users, so `hideLegend` renders that one
 * `sr-only` rather than duplicating visible text; every service row has no
 * other visible label attached to the radiogroup itself (the row header is a
 * `<th>` outside the `<td>` the fieldset lives in), so `hideLegend` defaults
 * to `false` — visible would still be correct, but is unnecessary because the
 * row already carries the name.
 */
function TriStateRadios({ name, legend, hideLegend = true, value, onChange, disabled = false, followLabel, onLabel, offLabel }) {
    return (
        <fieldset disabled={disabled} className="space-y-1.5">
            <legend className={hideLegend ? 'sr-only' : 'mb-1 text-sm font-semibold text-gray-800'}>{legend}</legend>
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input
                    type="radio"
                    name={name}
                    disabled={disabled}
                    checked={value === null}
                    onChange={() => onChange(null)}
                />
                {followLabel}
            </label>
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input
                    type="radio"
                    name={name}
                    disabled={disabled}
                    checked={value === true}
                    onChange={() => onChange(true)}
                />
                {onLabel}
            </label>
            <label className="flex items-center gap-2 text-sm text-gray-700">
                <input
                    type="radio"
                    name={name}
                    disabled={disabled}
                    checked={value === false}
                    onChange={() => onChange(false)}
                />
                {offLabel}
            </label>
        </fieldset>
    );
}

function ServicesTable({ services, deploymentFullyOff, choices, onChoice, servicesError }) {
    return (
        <section className="card overflow-hidden">
            <h2 className="p-4 pb-0 text-sm font-semibold text-gray-800">Services</h2>
            {servicesError && (
                <p role="alert" className="mx-4 mt-2 rounded border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                    {servicesError}
                </p>
            )}
            <table className="mt-2 w-full text-sm">
                <caption className="sr-only">Which AI services this tenant may use, and why</caption>
                <thead>
                    <tr className="border-t border-gray-200 bg-gray-50">
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Service</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Deployment</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Tenant</th>
                        <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Effective</th>
                    </tr>
                </thead>
                <tbody>
                    {services.map((row) => (
                        <ServiceRow
                            key={row.key}
                            row={row}
                            deploymentFullyOff={deploymentFullyOff}
                            choice={choices[row.key] ?? null}
                            onChoice={(value) => onChoice(row.key, value)}
                        />
                    ))}
                </tbody>
            </table>
        </section>
    );
}

function ServiceRow({ row, deploymentFullyOff, choice, onChoice }) {
    if (!row.implemented) {
        return (
            <tr className="border-t border-gray-100">
                <th scope="row" className="px-4 py-3 text-left font-normal text-gray-700">{row.label}</th>
                <td colSpan={2} className="px-4 py-3">
                    <Badge tone="grey">Not built in this release</Badge>
                </td>
                <td className="px-4 py-3 text-gray-500">—</td>
            </tr>
        );
    }

    const disabled = deploymentFullyOff || !row.deployment_enabled;
    let disabledReason = null;
    if (deploymentFullyOff) {
        disabledReason = 'AI is currently off for the whole deployment (see the banner above) — this switch has no effect until that changes.';
    } else if (!row.deployment_enabled) {
        disabledReason = 'Off — this deployment has not enabled this service. An operator must turn it on before this tenant can.';
    }

    return (
        <tr className="border-t border-gray-100 align-top">
            <th scope="row" className="px-4 py-3 text-left font-normal text-gray-700">{row.label}</th>
            <td className="px-4 py-3">
                <Badge tone={row.deployment_enabled ? 'emerald' : 'red'}>
                    {row.deployment_enabled ? 'On' : 'Off'}
                </Badge>
            </td>
            <td className="px-4 py-3">
                {disabled && (
                    <p className="mb-2 text-xs text-gray-700">{disabledReason}</p>
                )}
                {disabled && row.tenant_value !== null && (
                    <p className="mb-2 text-xs text-gray-500">
                        This tenant&rsquo;s own setting is currently {row.tenant_value ? 'On' : 'Off'}, but it has
                        no effect while {deploymentFullyOff ? 'AI is off for the whole deployment' : 'the deployment has this service off'}.
                    </p>
                )}
                <TriStateRadios
                    name={`ai_services_${row.key}`}
                    legend={row.label}
                    value={choice}
                    disabled={disabled}
                    onChange={onChoice}
                    followLabel={`Follow the deployment default (currently ${row.deployment_enabled ? 'On' : 'Off'})`}
                    onLabel="On for this tenant"
                    offLabel="Off for this tenant"
                />
            </td>
            <td className="px-4 py-3 text-gray-700">
                {row.effective ? (
                    <Badge tone="emerald">On</Badge>
                ) : (
                    <span className="text-xs">Off — {layerText(row.layer)}</span>
                )}
            </td>
        </tr>
    );
}

function UsageLimitSection({ data, setData, errors, month }) {
    return (
        <section className="card p-4">
            <h2 className="mb-1 text-sm font-semibold text-gray-800">Monthly usage limit</h2>
            <p className="mb-4 text-xs text-gray-500">
                {month.usage_month} so far: {month.call_count} calls
                {month.total_tokens !== null
                    ? `, ${month.total_tokens.toLocaleString()} tokens`
                    : ', tokens not reported by this backend'}
                {' — '}
                <Link href={route('tprm.settings.ai.usage')} className="text-[color:var(--color-primary)] underline">
                    View full usage report
                </Link>
            </p>

            <div className="grid gap-4 md:grid-cols-2">
                <label className="block text-sm">
                    <span className="mb-1 block font-medium text-gray-700">Token limit</span>
                    <input
                        type="number"
                        min={0}
                        className="w-full rounded border-gray-300 text-sm"
                        value={data.ai_monthly_token_cap}
                        onChange={(event) => setData('ai_monthly_token_cap', event.target.value)}
                    />
                    {!errors.ai_monthly_token_cap && (
                        <span className="mt-1 block text-xs text-gray-500">Leave blank for no limit</span>
                    )}
                    {errors.ai_monthly_token_cap && (
                        <span role="alert" className="mt-1 block text-xs text-red-600">{errors.ai_monthly_token_cap}</span>
                    )}
                </label>
                <label className="block text-sm">
                    <span className="mb-1 block font-medium text-gray-700">Call limit</span>
                    <input
                        type="number"
                        min={0}
                        className="w-full rounded border-gray-300 text-sm"
                        value={data.ai_monthly_call_cap}
                        onChange={(event) => setData('ai_monthly_call_cap', event.target.value)}
                    />
                    {!errors.ai_monthly_call_cap && (
                        <span className="mt-1 block text-xs text-gray-500">Leave blank for no limit</span>
                    )}
                    {errors.ai_monthly_call_cap && (
                        <span role="alert" className="mt-1 block text-xs text-red-600">{errors.ai_monthly_call_cap}</span>
                    )}
                </label>
            </div>
        </section>
    );
}

/**
 * A relative countdown computed from a real ISO timestamp the backend sent
 * (`breaker.opens_at`) — arithmetic on real data, not a fabricated figure.
 */
function relativeFromNow(iso) {
    const diffMs = new Date(iso).getTime() - Date.now();
    if (diffMs <= 0) return 'any moment now';
    const seconds = Math.round(diffMs / 1000);
    if (seconds < 60) return `in ${seconds}s`;
    return `in ${Math.round(seconds / 60)}m`;
}

function BreakerCard({ breaker, deployment, effective, profiles }) {
    const profile = profiles.find((item) => item.key === effective.endpoint_profile);
    const stateTone = { closed: 'emerald', open: 'red', half_open: 'amber' }[breaker.state] ?? 'grey';
    const stateLabel = { closed: 'Closed', open: 'Open', half_open: 'Half-open' }[breaker.state] ?? breaker.state;

    return (
        <section className="card p-4">
            <h2 className="mb-2 text-sm font-semibold text-gray-800">
                Breaker health — {profile?.label ?? effective.endpoint_profile}
            </h2>
            <div className="flex flex-wrap items-center gap-3 text-sm text-gray-700">
                <Badge tone={stateTone}>{stateLabel}</Badge>
                {breaker.state === 'open' && breaker.opens_at && (
                    <span>reopens for one probe at {relativeFromNow(breaker.opens_at)}</span>
                )}
                <span>Consecutive failures: {breaker.consecutive_failures}</span>
            </div>
            {!deployment.cache_store_is_shared && (
                <p className="mt-2 text-xs text-amber-800">
                    This deployment&rsquo;s cache store is not shared — this number resets per worker and cannot be
                    trusted as a whole-deployment signal.
                </p>
            )}
        </section>
    );
}
