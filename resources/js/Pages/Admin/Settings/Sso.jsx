import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';

const DRIVER_LABELS = { oidc: 'OpenID Connect', saml: 'SAML 2.0' };

function Section({ title, children }) {
    return (
        <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
            {children}
        </section>
    );
}

function Text({ name, label, optional, hint, form, type = 'text', ...props }) {
    return (
        <div>
            <InputLabel htmlFor={name}>
                <span className="text-xs font-medium text-gray-600">
                    {label} {optional && <span className="text-gray-400">(optional)</span>}
                </span>
            </InputLabel>
            <TextInput
                id={name}
                type={type}
                className="mt-1 block w-full text-sm"
                value={form.data[name] ?? ''}
                onChange={(e) => form.setData(name, e.target.value)}
                {...props}
            />
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
            <InputError message={form.errors[name]} className="mt-1" />
        </div>
    );
}

function Area({ name, label, rows = 5, form, hint }) {
    return (
        <div>
            <InputLabel htmlFor={name}>
                <span className="text-xs font-medium text-gray-600">{label}</span>
            </InputLabel>
            <textarea
                id={name}
                rows={rows}
                className="mt-1 block w-full text-xs font-mono border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                value={form.data[name] ?? ''}
                onChange={(e) => form.setData(name, e.target.value)}
            />
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
            <InputError message={form.errors[name]} className="mt-1" />
        </div>
    );
}

function Check({ name, form, title, hint }) {
    return (
        <label className="flex items-start gap-3 cursor-pointer">
            <input
                type="checkbox"
                className="mt-0.5 w-4 h-4 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                checked={Boolean(form.data[name])}
                onChange={(e) => form.setData(name, e.target.checked)}
            />
            <span className="text-sm text-gray-700">
                {title}
                {hint && <span className="block text-xs text-gray-500">{hint}</span>}
            </span>
        </label>
    );
}

/**
 * Single sign-on settings (migration Phase 6.2).
 *
 * Two things this page will not do.
 *
 * It never receives a stored secret — the client secret and the SP private key
 * arrive as booleans saying whether one is on file. A value the page receives
 * is a value in the Inertia payload and in the browser's memory. An empty
 * secret field means "leave it alone"; clearing one is an explicit checkbox.
 *
 * It offers only the roles the server said this administrator may hand out.
 * `super-admin` is absent unless they hold it, because a directory group
 * mapped to that role is the whole platform granted by an identity provider —
 * see SsoSettingsRequest.
 */
export default function Sso({ sso, roles, drivers, endpoints, missingRequirements }) {
    const { flash } = usePage().props;

    const form = useForm({
        enabled: Boolean(sso.enabled),
        driver: sso.driver,
        label: sso.label ?? '',
        slug: sso.slug ?? '',
        oidc_client_id: sso.oidc_client_id ?? '',
        oidc_client_secret: '',
        clear_oidc_client_secret: false,
        oidc_auth_url: sso.oidc_auth_url ?? '',
        oidc_token_url: sso.oidc_token_url ?? '',
        oidc_userinfo_url: sso.oidc_userinfo_url ?? '',
        oidc_scopes: sso.oidc_scopes ?? '',
        saml_idp_entity_id: sso.saml_idp_entity_id ?? '',
        saml_idp_sso_url: sso.saml_idp_sso_url ?? '',
        saml_idp_slo_url: sso.saml_idp_slo_url ?? '',
        saml_idp_x509_cert: sso.saml_idp_x509_cert ?? '',
        saml_sp_x509_cert: sso.saml_sp_x509_cert ?? '',
        saml_sp_private_key: '',
        clear_saml_sp_private_key: false,
        saml_email_attribute: sso.saml_email_attribute ?? '',
        saml_name_attribute: sso.saml_name_attribute ?? '',
        groups_claim: sso.groups_claim ?? 'groups',
        allowed_domains: sso.allowed_domains ?? '',
        role_map: sso.role_map?.length ? sso.role_map : [{ group: '', role: '' }],
        default_roles: sso.default_roles ?? [],
        auto_provision: Boolean(sso.auto_provision),
        sync_roles_on_login: Boolean(sso.sync_roles_on_login),
    });

    const isSaml = form.data.driver === 'saml';

    const setRow = (index, key, value) =>
        form.setData(
            'role_map',
            form.data.role_map.map((row, i) => (i === index ? { ...row, [key]: value } : row)),
        );

    const submit = (event) => {
        event.preventDefault();
        form.put(route('admin.settings.sso.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout title="Single Sign-On">
            <Head title="Single Sign-On" />

            <PageHeader
                title="Single Sign-On"
                subtitle="Point the product at your own identity provider"
                breadcrumbs={[{ label: 'Settings', href: route('admin.settings') }, { label: 'Single sign-on' }]}
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.warning && (
                <div className="mb-4 rounded-lg border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm text-yellow-800">
                    {flash.warning}
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                <h2 className="text-sm font-semibold text-gray-800 mb-1">Endpoints for your identity provider</h2>
                <p className="text-xs text-gray-500 mb-4">
                    Give these to whoever administers your directory. They are derived from your sign-in name below, so
                    they change if you change it.
                </p>

                <dl className="space-y-3 text-xs">
                    {Object.entries(endpoints).map(([label, value]) => (
                        <div key={label} className="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                            <dt className="w-56 shrink-0 text-gray-500">{label}</dt>
                            <dd className="flex-1 font-mono text-[11px] text-gray-800 break-all bg-gray-50 border border-gray-200 rounded px-2 py-1.5">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>

                {missingRequirements.length > 0 ? (
                    <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-xs text-yellow-800">
                        <span className="font-semibold">Not ready yet.</span> Still required:{' '}
                        {missingRequirements.join(', ')}.
                    </div>
                ) : (
                    sso.enabled && (
                        <div className="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-xs text-green-800">
                            Single sign-on is active. Users can sign in at{' '}
                            <span className="font-mono">{endpoints['Sign-in URL']}</span>.
                        </div>
                    )
                )}
            </div>

            <form onSubmit={submit} className="space-y-6">
                <Section title="General">
                    <Check
                        name="enabled"
                        form={form}
                        title="Offer single sign-on on the login screen"
                        hint="Turned back off automatically if a required field is missing."
                    />

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <Text name="label" label="Button label" form={form} required />
                        <Text
                            name="slug"
                            label="Sign-in name"
                            form={form}
                            hint="Lower case, letters, digits and hyphens. It appears in the sign-in URL."
                            required
                        />
                    </div>

                    <div className="sm:w-64">
                        <InputLabel htmlFor="driver">
                            <span className="text-xs font-medium text-gray-600">Protocol</span>
                        </InputLabel>
                        <select
                            id="driver"
                            className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.driver}
                            onChange={(e) => form.setData('driver', e.target.value)}
                        >
                            {drivers.map((driver) => (
                                <option key={driver} value={driver}>
                                    {DRIVER_LABELS[driver] ?? driver}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.driver} className="mt-1" />
                    </div>
                </Section>

                {!isSaml && (
                    <Section title="OpenID Connect">
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Text name="oidc_client_id" label="Client ID" form={form} />
                            <div>
                                <Text
                                    name="oidc_client_secret"
                                    label="Client secret"
                                    type="password"
                                    autoComplete="new-password"
                                    placeholder={sso.has_oidc_client_secret ? 'Stored — leave blank to keep' : ''}
                                    form={form}
                                />
                                {sso.has_oidc_client_secret && (
                                    <label className="flex items-center gap-2 mt-1.5 text-[11px] text-gray-500 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={form.data.clear_oidc_client_secret}
                                            onChange={(e) => form.setData('clear_oidc_client_secret', e.target.checked)}
                                        />
                                        Remove the stored secret
                                    </label>
                                )}
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Text name="oidc_auth_url" label="Authorization URL" type="url" form={form} />
                            <Text name="oidc_token_url" label="Token URL" type="url" form={form} />
                            <Text name="oidc_userinfo_url" label="Userinfo URL" type="url" form={form} />
                            <Text
                                name="oidc_scopes"
                                label="Scopes"
                                optional
                                form={form}
                                hint="Space separated. Defaults to openid profile email."
                            />
                        </div>
                        <p className="text-xs text-gray-500">
                            Endpoints must be https — an http endpoint would put the authorization code and the client
                            secret exchange on the wire in clear text.
                        </p>
                    </Section>
                )}

                {isSaml && (
                    <Section title="SAML 2.0">
                        <Text name="saml_idp_entity_id" label="IdP entity ID" form={form} />

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Text name="saml_idp_sso_url" label="IdP sign-on URL" type="url" form={form} />
                            <Text name="saml_idp_slo_url" label="IdP sign-out URL" type="url" optional form={form} />
                        </div>

                        <Area name="saml_idp_x509_cert" label="IdP signing certificate (X.509)" form={form} />

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Text name="saml_email_attribute" label="Email attribute" optional form={form} />
                            <Text name="saml_name_attribute" label="Display-name attribute" optional form={form} />
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <Area name="saml_sp_x509_cert" label="SP certificate" rows={4} form={form} />
                            <div>
                                <Area
                                    name="saml_sp_private_key"
                                    label="SP private key"
                                    rows={4}
                                    form={form}
                                    hint={sso.has_saml_sp_private_key ? 'Stored — leave blank to keep.' : undefined}
                                />
                                {sso.has_saml_sp_private_key && (
                                    <label className="flex items-center gap-2 mt-1.5 text-[11px] text-gray-500 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={form.data.clear_saml_sp_private_key}
                                            onChange={(e) =>
                                                form.setData('clear_saml_sp_private_key', e.target.checked)
                                            }
                                        />
                                        Remove the stored key
                                    </label>
                                )}
                            </div>
                        </div>
                    </Section>
                )}

                <Section title="Accounts and roles">
                    <Text
                        name="allowed_domains"
                        label="Permitted email domains"
                        optional
                        form={form}
                        hint="Comma separated. Leave blank to accept any address the provider asserts."
                    />

                    <div>
                        <p className="text-xs font-medium text-gray-600 mb-1">Directory group to role mapping</p>
                        <div className="space-y-2">
                            {form.data.role_map.map((row, index) => (
                                <div key={index} className="flex flex-col sm:flex-row gap-2">
                                    <input
                                        type="text"
                                        placeholder="Directory group"
                                        className="flex-1 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={row.group ?? ''}
                                        onChange={(e) => setRow(index, 'group', e.target.value)}
                                    />
                                    <select
                                        className="sm:w-64 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={row.role ?? ''}
                                        onChange={(e) => setRow(index, 'role', e.target.value)}
                                    >
                                        <option value="">No role</option>
                                        {roles.map((role) => (
                                            <option key={role} value={role}>
                                                {role}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() => form.setData('role_map', [...form.data.role_map, { group: '', role: '' }])}
                            className="mt-2 text-xs text-[#1A365D] font-medium hover:opacity-80"
                        >
                            + Add a mapping
                        </button>
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('role_map.'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
                    </div>

                    <div className="sm:w-64">
                        <Text name="groups_claim" label="Group claim name" form={form} />
                    </div>

                    <Check
                        name="sync_roles_on_login"
                        form={form}
                        title="Re-apply roles from the directory at every sign-in"
                        hint="Removing someone from a group in your directory then removes their access here. Turn this off if you grant roles manually as well."
                    />

                    <Check
                        name="auto_provision"
                        form={form}
                        title="Create accounts automatically on first sign-in"
                        hint="Leave off if someone should approve every new account — the stricter posture, and usually the one a regulator expects."
                    />

                    <div>
                        <p className="text-xs font-medium text-gray-600 mb-1">
                            Roles for a new account matching no group
                        </p>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            {roles.map((role) => (
                                <label
                                    key={role}
                                    className="flex items-center gap-2 p-2 rounded-lg bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={form.data.default_roles.includes(role)}
                                        onChange={() =>
                                            form.setData(
                                                'default_roles',
                                                form.data.default_roles.includes(role)
                                                    ? form.data.default_roles.filter((r) => r !== role)
                                                    : [...form.data.default_roles, role],
                                            )
                                        }
                                    />
                                    <span className="text-sm text-gray-700">{role}</span>
                                </label>
                            ))}
                        </div>
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('default_roles'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
                    </div>
                </Section>

                <div className="flex items-center gap-3">
                    <PrimaryButton disabled={form.processing}>Save settings</PrimaryButton>
                    <Link href={route('admin.settings')} className="text-sm text-gray-500 hover:text-gray-700">
                        Cancel
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
