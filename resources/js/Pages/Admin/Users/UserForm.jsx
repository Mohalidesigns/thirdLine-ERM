import { Link, useForm } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * The user form, shared by create and edit (migration Phase 6.1).
 *
 * THE ROLE LIST IS THE SERVER'S. It is `Role::all()` filtered to what this
 * actor may hand out — which removes the last hard-coded role list in the
 * product — and it is the same list StoreUserRequest validates against.
 *
 * `super-admin` is absent from that list unless the signed-in administrator
 * holds it: `Gate::before` answers true to every ability for anyone with that
 * role, so granting it is granting the platform. Until Phase 6.1 the roles
 * field had no rule on its elements at all.
 *
 * An administrator editing their own account gets the roles list disabled, and
 * UpdateUserRequest refuses the change server-side regardless — the field being
 * disabled is a courtesy, not the enforcement.
 */
export default function UserForm({ subject = null, businessUnits, roles, isSelf = false }) {
    const isEdit = subject !== null;

    const { data, setData, post, put, processing, errors } = useForm({
        name: subject?.name ?? '',
        email: subject?.email ?? '',
        staff_id: subject?.staff_id ?? '',
        job_title: subject?.job_title ?? '',
        department: subject?.department ?? '',
        phone: subject?.phone ?? '',
        business_unit_id: subject?.business_unit_id ?? '',
        roles: subject?.roles ?? [],
    });

    const toggleRole = (role) =>
        setData('roles', data.roles.includes(role) ? data.roles.filter((r) => r !== role) : [...data.roles, role]);

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            put(route('admin.users.update', subject.id));
        } else {
            post(route('admin.users.store'));
        }
    };

    const field = (name, label, props = {}) => (
        <div>
            <InputLabel htmlFor={name} value={label} />
            <TextInput
                id={name}
                className="mt-1 block w-full"
                value={data[name] ?? ''}
                onChange={(e) => setData(name, e.target.value)}
                {...props}
            />
            <InputError message={errors[name]} className="mt-1" />
        </div>
    );

    return (
        <form onSubmit={submit} className="space-y-6 max-w-3xl">
            <section className="bg-white rounded-xl border border-gray-200 p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div className="lg:col-span-2">{field('name', 'Full name', { required: true })}</div>
                {field('email', 'Email address', {
                    type: 'email',
                    required: true,
                })}
                {field('staff_id', 'Staff number', { required: true })}
                {field('job_title', 'Job title', { required: true })}
                {field('department', 'Department', { required: true })}
                {field('phone', 'Phone', { required: true })}

                <div>
                    <InputLabel htmlFor="business_unit_id" value="Business unit" />
                    <select
                        id="business_unit_id"
                        className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                        value={data.business_unit_id}
                        onChange={(e) => setData('business_unit_id', e.target.value)}
                        required
                    >
                        <option value="">Select a business unit</option>
                        {businessUnits.map((unit) => (
                            <option key={unit.id} value={unit.id}>
                                {unit.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.business_unit_id} className="mt-1" />
                </div>
            </section>

            <section className="bg-white rounded-xl border border-gray-200 p-6">
                <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Roles</h3>
                <p className="text-xs text-gray-500 mb-4">
                    {isSelf
                        ? 'You cannot change your own roles. Ask another administrator.'
                        : 'What this person may do. At least one is required.'}
                </p>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                    {roles.map((role) => (
                        <label
                            key={role}
                            className={`flex items-center gap-2 p-3 rounded-lg ${
                                isSelf
                                    ? 'bg-gray-50 cursor-not-allowed opacity-60'
                                    : 'bg-gray-50 hover:bg-blue-50 cursor-pointer'
                            }`}
                        >
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                checked={data.roles.includes(role)}
                                onChange={() => toggleRole(role)}
                                disabled={isSelf}
                            />
                            <span className="text-sm text-gray-700">{role}</span>
                        </label>
                    ))}
                </div>

                <InputError message={errors.roles} className="mt-2" />
                {Object.entries(errors)
                    .filter(([key]) => key.startsWith('roles.'))
                    .map(([key, message]) => (
                        <InputError key={key} message={message} className="mt-1" />
                    ))}
            </section>

            <div className="flex items-center justify-end gap-3">
                <Link href={route('admin.users.index')}>
                    <SecondaryButton type="button">Cancel</SecondaryButton>
                </Link>
                <PrimaryButton disabled={processing}>{isEdit ? 'Save changes' : 'Create user'}</PrimaryButton>
            </div>
        </form>
    );
}
