@extends('layouts.app')

@section('title', 'Single Sign-On')
@section('page-section', 'Administration')
@section('page-title', 'Single Sign-On')

@section('breadcrumbs')
    <a href="{{ route('risk.dashboard') }}" class="hover:text-primary">Dashboard</a>
    <span>/</span>
    <a href="{{ route('admin.settings') }}" class="hover:text-primary">Settings</a>
    <span>/</span>
    <span class="text-gray-700">Single Sign-On</span>
@endsection

@section('content')
<div x-data="{ driver: '{{ old('driver', $sso->driver) }}' }" class="max-w-4xl">

    {{-- What the client gives their IdP administrator --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-800 mb-1">Endpoints for your identity provider</h2>
        <p class="text-xs text-gray-500 mb-4">
            Give these to whoever administers your directory. They are derived from your sign-in
            name below, so they change if you change it.
        </p>

        <dl class="space-y-3 text-xs">
            @php
                $endpoints = [
                    'Sign-in URL' => $sso->signInUrl(),
                    'Redirect / Reply URL' => $sso->isSaml() ? $sso->acsUrl() : $sso->callbackUrl(),
                ];
                if ($sso->isSaml()) {
                    $endpoints['Service Provider entity ID'] = $sso->spEntityId();
                    $endpoints['SP metadata (XML)'] = $sso->metadataUrl();
                }
            @endphp

            @foreach ($endpoints as $label => $value)
                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4">
                    <dt class="w-56 shrink-0 text-gray-500">{{ $label }}</dt>
                    <dd class="flex-1 font-mono text-[11px] text-gray-800 break-all bg-gray-50 border border-gray-200 rounded px-2 py-1.5">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($missing = $sso->missingRequirements())
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-xs text-yellow-800">
                <span class="font-semibold">Not ready yet.</span>
                Still required: {{ implode(', ', $missing) }}.
            </div>
        @elseif ($sso->enabled)
            <div class="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-xs text-green-800">
                Single sign-on is active. Users can sign in at
                <span class="font-mono">{{ $sso->signInUrl() }}</span>.
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.settings.sso.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- General --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">General</h2>

            <label class="flex items-start gap-3">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $sso->enabled))
                       class="mt-0.5 w-4 h-4 rounded border border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    Enable single sign-on
                    <span class="block text-xs text-gray-500">
                        Password sign-in keeps working alongside this, so a directory outage cannot
                        lock you out of your own platform.
                    </span>
                </span>
            </label>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Button label</label>
                    <input type="text" name="label" value="{{ old('label', $sso->label) }}" required
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <p class="text-[11px] text-gray-400 mt-1">Shown on the sign-in page, e.g. “Sign in with Microsoft”.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Sign-in name</label>
                    <input type="text" name="slug" value="{{ old('slug', $sso->slug) }}" required
                           pattern="[a-z0-9][a-z0-9-]*"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono">
                    <p class="text-[11px] text-gray-400 mt-1">Lowercase letters, numbers and hyphens. Forms the URLs above.</p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Protocol</label>
                <select name="driver" x-model="driver"
                        class="w-full sm:w-64 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    <option value="oidc">OpenID Connect (Entra ID, Google Workspace, Okta)</option>
                    <option value="saml">SAML 2.0</option>
                </select>
            </div>
        </div>

        {{-- OIDC --}}
        <div x-show="driver === 'oidc'" x-cloak class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">OpenID Connect</h2>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Client ID</label>
                    <input type="text" name="oidc_client_id" value="{{ old('oidc_client_id', $sso->oidc_client_id) }}"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Client secret</label>
                    <input type="password" name="oidc_client_secret" autocomplete="new-password"
                           placeholder="{{ $sso->oidc_client_secret ? '•••••••• (leave blank to keep)' : 'Not set' }}"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    @if ($sso->oidc_client_secret)
                        <label class="flex items-center gap-2 mt-1.5 text-[11px] text-gray-500">
                            <input type="checkbox" name="clear_oidc_client_secret" value="1"
                                   class="w-4 h-4 rounded border border-gray-300 text-red-600 focus:ring-red-500">
                            Remove the stored secret
                        </label>
                    @endif
                </div>
            </div>

            @foreach ([
                'oidc_auth_url' => ['Authorization URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize'],
                'oidc_token_url' => ['Token URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token'],
                'oidc_userinfo_url' => ['Userinfo URL', 'https://graph.microsoft.com/oidc/userinfo'],
            ] as $field => [$label, $placeholder])
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                    <input type="url" name="{{ $field }}" value="{{ old($field, $sso->{$field}) }}"
                           placeholder="{{ $placeholder }}"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
                </div>
            @endforeach

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Scopes</label>
                <input type="text" name="oidc_scopes"
                       value="{{ old('oidc_scopes', implode(' ', $sso->oidc_scopes ?? ['openid', 'profile', 'email'])) }}"
                       class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
            </div>
        </div>

        {{-- SAML --}}
        <div x-show="driver === 'saml'" x-cloak class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">SAML 2.0</h2>
            <p class="text-xs text-gray-500">
                Assertions must be signed. Unsigned assertions are rejected — they carry no
                authentication guarantee.
            </p>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">IdP entity ID</label>
                <input type="text" name="saml_idp_entity_id" value="{{ old('saml_idp_entity_id', $sso->saml_idp_entity_id) }}"
                       class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">IdP sign-on URL</label>
                    <input type="url" name="saml_idp_sso_url" value="{{ old('saml_idp_sso_url', $sso->saml_idp_sso_url) }}"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">IdP sign-out URL <span class="text-gray-400">(optional)</span></label>
                    <input type="url" name="saml_idp_slo_url" value="{{ old('saml_idp_slo_url', $sso->saml_idp_slo_url) }}"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">IdP signing certificate (X.509)</label>
                <textarea name="saml_idp_x509_cert" rows="5"
                          placeholder="-----BEGIN CERTIFICATE----- …"
                          class="w-full text-xs font-mono px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">{{ old('saml_idp_x509_cert', $sso->saml_idp_x509_cert) }}</textarea>
                <p class="text-[11px] text-gray-400 mt-1">Paste with or without the BEGIN/END lines.</p>
            </div>

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Email attribute <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="saml_email_attribute" value="{{ old('saml_email_attribute', $sso->saml_email_attribute) }}"
                           placeholder="auto-detect, falls back to NameID"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Display-name attribute <span class="text-gray-400">(optional)</span></label>
                    <input type="text" name="saml_name_attribute" value="{{ old('saml_name_attribute', $sso->saml_name_attribute) }}"
                           placeholder="auto-detect"
                           class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
                </div>
            </div>

            <details class="border-t border-gray-100 pt-4">
                <summary class="text-xs font-medium text-gray-600 cursor-pointer">
                    Service provider signing key (only if your IdP requires signed requests)
                </summary>
                <div class="mt-3 space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">SP certificate</label>
                        <textarea name="saml_sp_x509_cert" rows="4"
                                  class="w-full text-xs font-mono px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">{{ old('saml_sp_x509_cert', $sso->saml_sp_x509_cert) }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">SP private key</label>
                        <textarea name="saml_sp_private_key" rows="4"
                                  placeholder="{{ $sso->saml_sp_private_key ? '•••••••• (leave blank to keep)' : 'Not set' }}"
                                  class="w-full text-xs font-mono px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
                        @if ($sso->saml_sp_private_key)
                            <label class="flex items-center gap-2 mt-1.5 text-[11px] text-gray-500">
                                <input type="checkbox" name="clear_saml_sp_private_key" value="1"
                                       class="w-4 h-4 rounded border border-gray-300 text-red-600 focus:ring-red-500">
                                Remove the stored private key
                            </label>
                        @endif
                        <p class="text-[11px] text-gray-400 mt-1">Stored encrypted and never displayed again.</p>
                    </div>
                </div>
            </details>
        </div>

        {{-- Accounts and roles --}}
        <div class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <h2 class="text-sm font-semibold text-gray-800">Accounts and roles</h2>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Permitted email domains</label>
                <input type="text" name="allowed_domains"
                       value="{{ old('allowed_domains', implode(', ', $sso->allowed_domains ?? [])) }}"
                       placeholder="firstbank.com, firstbank.ng"
                       class="w-full text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                <p class="text-[11px] text-gray-400 mt-1">
                    Sign-in is refused for addresses outside these domains, even with a valid assertion.
                    Also lets staff reach sign-in by typing their email instead of the URL.
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Directory group to role mapping</label>
                <p class="text-[11px] text-gray-400 mb-2">
                    Groups with no entry grant nothing. Only the roles listed here can be granted,
                    so naming a group in your directory can never invent a new level of access.
                </p>

                @php $mapRows = array_slice(array_merge(collect($sso->role_map ?? [])->map(fn ($r, $g) => ['group' => $g, 'role' => $r])->values()->all(), array_fill(0, 5, ['group' => '', 'role' => ''])), 0, 8); @endphp

                <div class="space-y-2">
                    @foreach ($mapRows as $i => $row)
                        <div class="flex gap-2">
                            <input type="text" name="role_map[{{ $i }}][group]" value="{{ $row['group'] }}"
                                   placeholder="Directory group name"
                                   class="flex-1 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <select name="role_map[{{ $i }}][role]"
                                    class="w-56 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">— no role —</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role }}" @selected($row['role'] === $role)>{{ $role }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Group claim name</label>
                <input type="text" name="groups_claim" value="{{ old('groups_claim', $sso->groups_claim) }}"
                       class="w-full sm:w-64 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent font-mono text-xs">
            </div>

            <label class="flex items-start gap-3">
                <input type="checkbox" name="sync_roles_on_login" value="1" @checked(old('sync_roles_on_login', $sso->sync_roles_on_login))
                       class="mt-0.5 w-4 h-4 rounded border border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    Re-apply roles from the directory at every sign-in
                    <span class="block text-xs text-gray-500">
                        Removing someone from a group in your directory then removes their access here.
                        Turn this off if you grant roles manually as well.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3">
                <input type="checkbox" name="auto_provision" value="1" @checked(old('auto_provision', $sso->auto_provision))
                       class="mt-0.5 w-4 h-4 rounded border border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    Create accounts automatically on first sign-in
                    <span class="block text-xs text-gray-500">
                        Leave off if someone should approve every new account — the stricter posture,
                        and usually the one a regulator expects.
                    </span>
                </span>
            </label>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Roles for a new account matching no group</label>
                <select name="default_roles[]" multiple size="4"
                        class="w-full sm:w-72 text-sm px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                    @foreach ($roles as $role)
                        <option value="{{ $role }}" @selected(in_array($role, $sso->default_roles ?? [], true))>{{ $role }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:opacity-90">
                Save settings
            </button>
            <a href="{{ route('admin.settings') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
        </div>
    </form>
</div>
@endsection
