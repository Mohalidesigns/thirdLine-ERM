import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';
import { formatDateTime } from '@/utils';

function tryRoute(name) {
    try {
        return route(name);
    } catch {
        return null;
    }
}

export default function Edit({ profile, mfaAvailable }) {
    const mfaSetupUrl = mfaAvailable ? tryRoute('mfa.setup') : null;

    return (
        <AuthenticatedLayout title="Profile">
            <Head title="Profile" />
            <PageHeader title="Your account" subtitle={`${profile.organization || ''}${profile.roles?.length ? ' · ' + profile.roles.join(', ') : ''}`} />

            <div className="mx-auto max-w-3xl space-y-6">
                <div className="card">
                    <div className="card-body grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Staff ID</p>
                            <p className="mt-1 text-gray-800">{profile.staff_id || '—'}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Last sign-in</p>
                            <p className="mt-1 text-gray-800">{formatDateTime(profile.last_login_at)}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Password changed</p>
                            <p className="mt-1 text-gray-800">{formatDateTime(profile.password_changed_at)}</p>
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-gray-400">Two-factor</p>
                            <p className="mt-1 text-gray-800">{profile.mfa_enabled ? 'Enabled' : 'Not enrolled'}</p>
                        </div>
                    </div>
                </div>

                <div className="card">
                    <div className="card-body">
                        <UpdateProfileInformationForm profile={profile} className="max-w-xl" />
                    </div>
                </div>

                <div className="card">
                    <div className="card-body">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>
                </div>

                <div className="card">
                    <div className="card-body">
                        <h2 className="text-lg font-medium text-gray-900">Two-Factor Authentication</h2>
                        {mfaSetupUrl ? (
                            <>
                                <p className="mt-1 text-sm text-gray-600">
                                    {profile.mfa_enabled
                                        ? 'An authenticator app is enrolled on this account. You can re-enrol with a new key at any time.'
                                        : 'Add a second factor: a six-digit code from an authenticator app, required at every sign-in.'}
                                </p>
                                <Link href={mfaSetupUrl} className="btn-secondary mt-4 inline-flex items-center gap-2 text-sm">
                                    <span className="material-symbols-outlined text-[18px]">verified_user</span>
                                    {profile.mfa_enabled ? 'Re-enrol authenticator' : 'Set up two-factor authentication'}
                                </Link>
                                <p className="mt-3 text-xs text-gray-400">Lost your authenticator? Your administrator can reset it from User Management.</p>
                            </>
                        ) : (
                            <p className="mt-1 text-sm text-gray-600">Two-factor authentication is not enabled on this deployment.</p>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
