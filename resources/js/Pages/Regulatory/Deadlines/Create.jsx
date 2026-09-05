import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

const humanise = (value) => String(value).replaceAll('_', ' ');

/**
 * Add a filing deadline (migration Phase 5.3).
 *
 * `responsible_id` reaches a tenant-bound Rule::exists in StoreDeadlineRequest.
 * It had no rule at all before: any user id in the request body was written
 * into the row, including one belonging to another institution, and the
 * calendar then named that person as accountable for a CBN return.
 */
export default function Create({ users, frequencies }) {
    const { data, setData, post, processing, errors } = useForm({
        regulator: '',
        report_type: '',
        title: '',
        description: '',
        deadline_date: '',
        frequency: 'monthly',
        responsible_id: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('risk.regulatory.store-deadline'));
    };

    return (
        <AuthenticatedLayout title="New Regulatory Deadline">
            <Head title="New Regulatory Deadline" />

            <PageHeader title="New Regulatory Deadline" subtitle="A return this institution owes a regulator" />

            <form onSubmit={submit} className="space-y-6">
                <section className="bg-white rounded-xl border border-gray-200 p-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <InputLabel htmlFor="regulator" value="Regulator" />
                        <TextInput
                            id="regulator"
                            className="mt-1 block w-full"
                            value={data.regulator}
                            onChange={(e) => setData('regulator', e.target.value)}
                            placeholder="e.g. CBN"
                            required
                        />
                        <InputError message={errors.regulator} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="report_type" value="Return type" />
                        <TextInput
                            id="report_type"
                            className="mt-1 block w-full"
                            value={data.report_type}
                            onChange={(e) => setData('report_type', e.target.value)}
                            placeholder="e.g. ORMS quarterly return"
                            required
                        />
                        <InputError message={errors.report_type} className="mt-1" />
                    </div>

                    <div className="lg:col-span-2">
                        <InputLabel htmlFor="title" value="Title" />
                        <TextInput
                            id="title"
                            className="mt-1 block w-full"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            required
                        />
                        <InputError message={errors.title} className="mt-1" />
                    </div>

                    <div className="lg:col-span-2">
                        <InputLabel htmlFor="description" value="Description (optional)" />
                        <textarea
                            id="description"
                            rows={3}
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="deadline_date" value="Due date" />
                        <TextInput
                            id="deadline_date"
                            type="date"
                            className="mt-1 block w-full"
                            value={data.deadline_date}
                            onChange={(e) => setData('deadline_date', e.target.value)}
                            required
                        />
                        <InputError message={errors.deadline_date} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="frequency" value="Frequency" />
                        <select
                            id="frequency"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                            required
                        >
                            {frequencies.map((frequency) => (
                                <option key={frequency} value={frequency}>
                                    {humanise(frequency)}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.frequency} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="responsible_id" value="Responsible officer (optional)" />
                        <select
                            id="responsible_id"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.responsible_id}
                            onChange={(e) => setData('responsible_id', e.target.value)}
                        >
                            <option value="">Unassigned</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.responsible_id} className="mt-1" />
                    </div>
                </section>

                <div className="flex items-center justify-end gap-3">
                    <Link href={route('risk.regulatory.deadlines')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={processing}>Add deadline</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
