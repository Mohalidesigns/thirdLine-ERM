import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';

const ROLE_COLOURS = {
    'super-admin': 'bg-red-50 border-red-200 text-red-900',
    'chief-risk-officer': 'bg-purple-50 border-purple-200 text-purple-900',
    'risk-manager': 'bg-blue-50 border-blue-200 text-blue-900',
    'risk-owner': 'bg-cyan-50 border-cyan-200 text-cyan-900',
    'risk-analyst': 'bg-indigo-50 border-indigo-200 text-indigo-900',
    'compliance-officer': 'bg-green-50 border-green-200 text-green-900',
    'board-member': 'bg-yellow-50 border-yellow-200 text-yellow-900',
    'loss-event-manager': 'bg-orange-50 border-orange-200 text-orange-900',
    'issue-manager': 'bg-teal-50 border-teal-200 text-teal-900',
};

const titleise = (name) => name.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const dateTime = (value) =>
    value
        ? new Date(value).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : null;

const date = (value) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null);

function Field({ label, children }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 uppercase tracking-wide">{label}</dt>
            <dd className="text-sm text-gray-900 font-medium">{children}</dd>
        </div>
    );
}

const TONES = {
    orange: { surface: 'bg-orange-50 hover:bg-orange-100 border-orange-200 text-orange-700', hint: 'text-orange-500' },
    red: { surface: 'bg-red-50 hover:bg-red-100 border-red-200 text-red-700', hint: 'text-red-500' },
    green: { surface: 'bg-green-50 hover:bg-green-100 border-green-200 text-green-700', hint: 'text-green-500' },
    blue: { surface: 'bg-blue-50 hover:bg-blue-100 border-blue-200 text-blue-700', hint: 'text-blue-500' },
};

function ActionButton({ tone, icon, label, hint, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`w-full px-4 py-3 font-medium rounded-lg border transition flex items-center gap-3 text-left ${tone.surface}`}
        >
            <span className="material-symbols-outlined text-xl">{icon}</span>
            <span>
                <span className="block text-sm font-semibold">{label}</span>
                <span className={`block text-xs ${tone.hint}`}>{hint}</span>
            </span>
        </button>
    );
}

function Pill({ tone, children }) {
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${tone}`}>
            {children}
        </span>
    );
}

/**
 * The user profile (migration Phase 6.1).
 *
 * Every destructive control here is rendered only when the server said the
 * signed-in administrator may use it — `canDeactivate` is false for your own
 * account, which is UserPolicy::deactivate's rule, not a UI opinion. The
 * policy answers the same way if the request arrives anyway.
 */
export default function Show({ subject, isSelf, canManage, canDeactivate, canResetPassword }) {
    const initials = subject.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join('');

    const confirmPost = (message, url, method = 'post') => {
        if (window.confirm(message)) {
            router[method](url, {}, { preserveScroll: true });
        }
    };

    const modules = Object.entries(subject.permissions ?? {});

    return (
        <AuthenticatedLayout title={subject.name}>
            <Head title={subject.name} />

            <div className="space-y-6">
                <Link
                    href={route('admin.users.index')}
                    className="text-[#1A365D] hover:opacity-80 font-medium inline-flex items-center gap-1 text-sm"
                >
                    <span className="material-symbols-outlined text-lg">arrow_back</span>
                    Back to Users
                </Link>

                <div className="bg-white rounded-lg shadow border border-gray-100 overflow-hidden">
                    <div className="bg-gradient-to-r from-[#1A365D] to-[#2C5282] p-6 text-white">
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex items-center gap-4">
                                <div className="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center text-2xl font-bold">
                                    {initials}
                                </div>
                                <div>
                                    <h2 className="text-2xl font-bold">{subject.name}</h2>
                                    <p className="text-white/80">
                                        {subject.job_title} &bull; {subject.department}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 mt-2">
                                        {subject.roles.map((role) => (
                                            <span
                                                key={role.name}
                                                className="px-2 py-0.5 bg-white/20 rounded-full text-xs font-medium"
                                            >
                                                {titleise(role.name)}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </div>

                            {canManage && (
                                <Link
                                    href={route('admin.users.edit', subject.id)}
                                    className="px-4 py-2 bg-white text-[#1A365D] font-semibold rounded-lg hover:bg-white/90 transition inline-flex items-center gap-2 shrink-0"
                                >
                                    <span className="material-symbols-outlined text-lg">edit</span>
                                    Edit User
                                </Link>
                            )}
                        </div>
                    </div>

                    <div className="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <h3 className="text-sm font-semibold text-gray-700 mb-4">Contact Information</h3>
                            <dl className="space-y-3">
                                <Field label="Email">{subject.email}</Field>
                                <Field label="Phone">{subject.phone ?? '-'}</Field>
                                <Field label="Staff ID">{subject.staff_id ?? '-'}</Field>
                            </dl>
                        </div>

                        <div>
                            <h3 className="text-sm font-semibold text-gray-700 mb-4">Account Status</h3>
                            <dl className="space-y-3">
                                <Field label="Status">
                                    {subject.is_active ? (
                                        <Pill tone="bg-green-100 text-green-800">
                                            <span className="w-1.5 h-1.5 bg-green-500 rounded-full mr-1.5" />
                                            Active
                                        </Pill>
                                    ) : (
                                        <Pill tone="bg-gray-100 text-gray-700">
                                            <span className="w-1.5 h-1.5 bg-gray-400 rounded-full mr-1.5" />
                                            Inactive
                                        </Pill>
                                    )}
                                </Field>
                                <Field label="Last Login">{dateTime(subject.last_login_at) ?? 'Never logged in'}</Field>
                                <Field label="MFA Status">
                                    {subject.mfa_enabled ? (
                                        <Pill tone="bg-blue-100 text-blue-800">Enabled</Pill>
                                    ) : (
                                        <Pill tone="bg-gray-100 text-gray-700">Disabled</Pill>
                                    )}
                                </Field>
                                <Field label="Password Changed">{date(subject.password_changed_at) ?? 'Never'}</Field>
                                {subject.is_locked && (
                                    <Field label="Account Locked">
                                        <Pill tone="bg-red-100 text-red-800">
                                            Until {dateTime(subject.locked_until)}
                                        </Pill>
                                    </Field>
                                )}
                            </dl>
                        </div>

                        <div>
                            <h3 className="text-sm font-semibold text-gray-700 mb-4">Organization</h3>
                            <dl className="space-y-3">
                                <Field label="Business Unit">{subject.business_unit ?? 'Not assigned'}</Field>
                                <Field label="Organization">{subject.organization ?? '-'}</Field>
                                <Field label="Created">{date(subject.created_at)}</Field>
                                <Field label="Last Activity">
                                    {dateTime(subject.last_activity_at) ?? 'No activity'}
                                </Field>
                            </dl>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="bg-white rounded-lg shadow border border-gray-100 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Assigned Roles</h3>
                        {subject.roles.length > 0 ? (
                            <div className="space-y-2">
                                {subject.roles.map((role) => (
                                    <div
                                        key={role.name}
                                        className={`flex items-center justify-between p-3 rounded-lg border ${
                                            ROLE_COLOURS[role.name] ?? 'bg-gray-50 border-gray-200 text-gray-900'
                                        }`}
                                    >
                                        <span className="text-sm font-medium">{titleise(role.name)}</span>
                                        <span className="text-xs opacity-70">{role.permission_count} permissions</span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-gray-500">No roles assigned</p>
                        )}
                    </div>

                    <div className="bg-white rounded-lg shadow border border-gray-100 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Effective Permissions</h3>
                        {modules.length > 0 ? (
                            <div className="space-y-3 max-h-64 overflow-y-auto pr-1">
                                {modules.map(([module, actions]) => (
                                    <div key={module}>
                                        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                                            {module}
                                        </p>
                                        <div className="flex flex-wrap gap-1">
                                            {actions.map((action) => (
                                                <span
                                                    key={action}
                                                    className="px-2 py-0.5 bg-gray-100 rounded text-xs text-gray-700"
                                                >
                                                    {action}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-gray-500">No permissions</p>
                        )}
                    </div>
                </div>

                {(canResetPassword || canDeactivate) && (
                    <div className="bg-white rounded-lg shadow border border-gray-100 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Security Actions</h3>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            {canResetPassword && (
                                <ActionButton
                                    tone={TONES.orange}
                                    icon="lock_reset"
                                    label="Reset Password"
                                    hint="Generate new temporary password"
                                    onClick={() =>
                                        confirmPost(
                                            'Reset password? The user will receive a new temporary password.',
                                            route('admin.users.reset-password', subject.id),
                                        )
                                    }
                                />
                            )}

                            {canDeactivate &&
                                (subject.is_active ? (
                                    <ActionButton
                                        tone={TONES.red}
                                        icon="block"
                                        label="Deactivate User"
                                        hint="Prevent this user from logging in"
                                        onClick={() =>
                                            confirmPost(
                                                'Deactivate this user? They will be unable to log in.',
                                                route('admin.users.toggle-active', subject.id),
                                                'patch',
                                            )
                                        }
                                    />
                                ) : (
                                    <ActionButton
                                        tone={TONES.green}
                                        icon="check_circle"
                                        label="Activate User"
                                        hint="Allow this user to log in"
                                        onClick={() =>
                                            confirmPost(
                                                'Activate this user?',
                                                route('admin.users.toggle-active', subject.id),
                                                'patch',
                                            )
                                        }
                                    />
                                ))}

                            {canResetPassword && subject.is_locked && (
                                <ActionButton
                                    tone={TONES.blue}
                                    icon="lock_open"
                                    label="Unlock Account"
                                    hint="Clear lockout and reset password"
                                    onClick={() =>
                                        confirmPost(
                                            'Unlock account and reset password?',
                                            route('admin.users.reset-password', subject.id),
                                        )
                                    }
                                />
                            )}
                        </div>

                        {isSelf && (
                            <p className="text-xs text-gray-500 mt-4">
                                This is your own account. Deactivating yourself is not offered.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
