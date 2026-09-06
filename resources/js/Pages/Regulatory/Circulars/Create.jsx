import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';

/**
 * Record a regulator's circular (migration Phase 5.3).
 *
 * THE AFFECTED-RISK PICKER IS NEW, and it is why a query stops being dead.
 * `createCircular()` has always loaded every risk in the organisation, the
 * Blade form rendered none of them, and `affected_risk_ids` — a column the
 * store path writes and the model casts to an array — could not be set through
 * the interface at all. Each selected id now goes through a tenant-bound
 * Rule::exists; `assigned_to` did too, having previously had no rule.
 */
export default function Create({ users, risks, impactLevels }) {
    const { data, setData, post, processing, errors } = useForm({
        regulator: '',
        circular_ref: '',
        title: '',
        date_issued: '',
        effective_date: '',
        summary: '',
        impact_level: 'medium',
        action_required: '',
        assigned_to: '',
        affected_risk_ids: [],
    });

    const toggleRisk = (id) => {
        setData(
            'affected_risk_ids',
            data.affected_risk_ids.includes(id)
                ? data.affected_risk_ids.filter((r) => r !== id)
                : [...data.affected_risk_ids, id],
        );
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('risk.regulatory.store-circular'));
    };

    return (
        <AuthenticatedLayout title="Record Circular">
            <Head title="Record Circular" />

            <PageHeader title="Record a Regulatory Circular" subtitle="What the regulator issued, and what it requires of us" />

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
                        <InputLabel htmlFor="circular_ref" value="Circular reference" />
                        <TextInput
                            id="circular_ref"
                            className="mt-1 block w-full"
                            value={data.circular_ref}
                            onChange={(e) => setData('circular_ref', e.target.value)}
                            required
                        />
                        <InputError message={errors.circular_ref} className="mt-1" />
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

                    <div>
                        <InputLabel htmlFor="date_issued" value="Date issued" />
                        <TextInput
                            id="date_issued"
                            type="date"
                            className="mt-1 block w-full"
                            value={data.date_issued}
                            onChange={(e) => setData('date_issued', e.target.value)}
                            required
                        />
                        <InputError message={errors.date_issued} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="effective_date" value="Effective date (optional)" />
                        <TextInput
                            id="effective_date"
                            type="date"
                            className="mt-1 block w-full"
                            value={data.effective_date}
                            onChange={(e) => setData('effective_date', e.target.value)}
                        />
                        <InputError message={errors.effective_date} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="impact_level" value="Impact level" />
                        <select
                            id="impact_level"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.impact_level}
                            onChange={(e) => setData('impact_level', e.target.value)}
                        >
                            {impactLevels.map((level) => (
                                <option key={level} value={level}>
                                    {level}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.impact_level} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="assigned_to" value="Assigned to (optional)" />
                        <select
                            id="assigned_to"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.assigned_to}
                            onChange={(e) => setData('assigned_to', e.target.value)}
                        >
                            <option value="">Unassigned</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>
                                    {user.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.assigned_to} className="mt-1" />
                    </div>

                    <div className="lg:col-span-2">
                        <InputLabel htmlFor="summary" value="Summary (optional)" />
                        <textarea
                            id="summary"
                            rows={3}
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.summary}
                            onChange={(e) => setData('summary', e.target.value)}
                        />
                        <InputError message={errors.summary} className="mt-1" />
                    </div>

                    <div className="lg:col-span-2">
                        <InputLabel htmlFor="action_required" value="Action required (optional)" />
                        <textarea
                            id="action_required"
                            rows={3}
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.action_required}
                            onChange={(e) => setData('action_required', e.target.value)}
                        />
                        <InputError message={errors.action_required} className="mt-1" />
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 className="text-lg font-semibold text-[#1A365D] mb-1">Affected Risks</h2>
                    <p className="text-xs text-gray-500 mb-4">
                        Which entries in the register this circular bears on. Optional, and changeable later.
                    </p>

                    {risks.length === 0 ? (
                        <p className="text-sm text-gray-400 italic py-4">This organisation has no active risks to link.</p>
                    ) : (
                        <div className="max-h-72 overflow-y-auto divide-y divide-gray-100 border border-gray-100 rounded-lg">
                            {risks.map((risk) => (
                                <label key={risk.id} className="flex items-center gap-3 px-4 py-2 hover:bg-blue-50 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={data.affected_risk_ids.includes(risk.id)}
                                        onChange={() => toggleRisk(risk.id)}
                                    />
                                    <span className="text-xs text-gray-400 w-28 shrink-0">{risk.risk_code}</span>
                                    <span className="text-sm text-gray-700">{risk.title}</span>
                                </label>
                            ))}
                        </div>
                    )}

                    <InputError message={errors.affected_risk_ids} className="mt-2" />
                </section>

                <div className="flex items-center justify-end gap-3">
                    <Link href={route('risk.regulatory.circulars')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={processing}>Record circular</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
