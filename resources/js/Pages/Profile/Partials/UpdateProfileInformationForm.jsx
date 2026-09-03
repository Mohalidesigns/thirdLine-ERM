import { Transition } from '@headlessui/react';
import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';

export default function UpdateProfileInformationForm({ profile, className = '' }) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: profile.name || '',
        job_title: profile.job_title || '',
        department: profile.department || '',
        phone: profile.phone || '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Profile Information</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Your email address is your sign-in identity and is managed by your administrator or your organisation's directory.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Name" />
                    <TextInput id="name" className="mt-1 block w-full form-input" value={data.name} onChange={(e) => setData('name', e.target.value)} required isFocused autoComplete="name" />
                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Email" />
                    <TextInput id="email" type="email" className="mt-1 block w-full form-input bg-gray-50 text-gray-500" value={profile.email} disabled readOnly />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <InputLabel htmlFor="job_title" value="Job title" />
                        <TextInput id="job_title" className="mt-1 block w-full form-input" value={data.job_title} onChange={(e) => setData('job_title', e.target.value)} autoComplete="organization-title" />
                        <InputError className="mt-2" message={errors.job_title} />
                    </div>
                    <div>
                        <InputLabel htmlFor="department" value="Department" />
                        <TextInput id="department" className="mt-1 block w-full form-input" value={data.department} onChange={(e) => setData('department', e.target.value)} />
                        <InputError className="mt-2" message={errors.department} />
                    </div>
                </div>

                <div>
                    <InputLabel htmlFor="phone" value="Phone" />
                    <TextInput id="phone" className="mt-1 block w-full form-input" value={data.phone} onChange={(e) => setData('phone', e.target.value)} autoComplete="tel" />
                    <InputError className="mt-2" message={errors.phone} />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton disabled={processing}>Save</PrimaryButton>
                    <Transition show={recentlySuccessful} enter="transition ease-in-out" enterFrom="opacity-0" leave="transition ease-in-out" leaveTo="opacity-0">
                        <p className="text-sm text-gray-600">Saved.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}
