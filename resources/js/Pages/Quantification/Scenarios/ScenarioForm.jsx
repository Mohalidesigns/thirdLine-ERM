import { useForm } from '@inertiajs/react';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Link } from '@inertiajs/react';

/**
 * The scenario form, shared by create and edit (migration Phase 5.2).
 *
 * THE EDIT SCREEN HAD NEVER WORKED. `editScenario()` rendered
 * create-scenario.blade.php, whose every field was `old(...)` with no fallback
 * to the record — so opening a scenario for editing showed a BLANK form. Worse,
 * that form's action was hardcoded to `store-scenario`, so saving it created a
 * SECOND scenario instead of updating the first. The PUT route, its controller
 * method and its validation were unreachable from the interface: nothing in the
 * codebase referenced `risk.quantification.update-scenario` at all.
 *
 * `initial` comes from ScenarioService::toFormValues(), the exact inverse of
 * the mapping the write path uses, so what a user typed is what they see again.
 */
export default function ScenarioForm({ scenario = null, initial, risks, categories, distributions }) {
    const isEdit = scenario !== null;

    const { data, setData, post, put, processing, errors } = useForm(initial);

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            put(route('risk.quantification.update-scenario', scenario.id));
        } else {
            post(route('risk.quantification.store-scenario'));
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
        <form onSubmit={submit} className="space-y-6">
            <section className="bg-white rounded-xl border border-gray-200 p-6">
                <h2 className="text-lg font-semibold text-[#1A365D] mb-6">Scenario Definition</h2>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div className="lg:col-span-2">
                        {field('name', 'Scenario name', { required: true, placeholder: 'e.g. Credit Default — Oil & Gas Sector' })}
                    </div>

                    <div className="lg:col-span-2">
                        <InputLabel htmlFor="description" value="Description" />
                        <textarea
                            id="description"
                            rows={3}
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.description ?? ''}
                            onChange={(e) => setData('description', e.target.value)}
                            required
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="risk_category" value="CBN risk category" />
                        <select
                            id="risk_category"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.risk_category ?? ''}
                            onChange={(e) => setData('risk_category', e.target.value)}
                            required
                        >
                            <option value="">Select category</option>
                            {categories.map((category) => (
                                <option key={category} value={category}>
                                    {category}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.risk_category} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="linked_risk_id" value="Linked register risk (optional)" />
                        <select
                            id="linked_risk_id"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.linked_risk_id ?? ''}
                            onChange={(e) => setData('linked_risk_id', e.target.value)}
                        >
                            <option value="">Not linked</option>
                            {risks.map((risk) => (
                                <option key={risk.id} value={risk.id}>
                                    {risk.risk_code} — {risk.title}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.linked_risk_id} className="mt-1" />
                    </div>
                </div>
            </section>

            <section className="bg-white rounded-xl border border-gray-200 p-6">
                <h2 className="text-lg font-semibold text-[#1A365D] mb-1">Loss Distribution</h2>
                <p className="text-xs text-gray-500 mb-6">
                    Frequency is Poisson; severity is drawn from a log-normal fitted to the mean and standard deviation
                    below. Only distributions the simulation engine can actually draw are offered — recording a
                    heavy-tailed distribution the engine simulates as log-normal would give you a number, just not the
                    number you asked for.
                </p>

                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <InputLabel htmlFor="distribution_type" value="Severity distribution" />
                        <select
                            id="distribution_type"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.distribution_type ?? ''}
                            onChange={(e) => setData('distribution_type', e.target.value)}
                            required
                        >
                            {distributions.map((distribution) => (
                                <option key={distribution} value={distribution}>
                                    {distribution.charAt(0).toUpperCase() + distribution.slice(1)}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.distribution_type} className="mt-1" />
                    </div>

                    {field('frequency_per_year', 'Expected events per year', {
                        type: 'number',
                        step: '0.1',
                        min: '0',
                        required: true,
                        placeholder: 'e.g. 2.5',
                    })}

                    {field('mean', 'Mean loss per event (₦)', {
                        type: 'number',
                        min: '0',
                        required: true,
                        placeholder: 'e.g. 50000000',
                    })}

                    {field('std_dev', 'Standard deviation (₦)', {
                        type: 'number',
                        min: '0',
                        placeholder: 'e.g. 15000000',
                    })}

                    {field('min_loss', 'Minimum loss (₦, optional)', { type: 'number', min: '0' })}
                    {field('max_loss', 'Maximum loss (₦, optional)', { type: 'number', min: '0' })}
                </div>
            </section>

            {isEdit && (
                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h2 className="text-lg font-semibold text-[#1A365D] mb-6">Status</h2>
                    <div className="max-w-xs">
                        <InputLabel htmlFor="status" value="Status" />
                        <select
                            id="status"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={data.status ?? 'active'}
                            onChange={(e) => setData('status', e.target.value)}
                        >
                            <option value="draft">Draft</option>
                            <option value="active">Active</option>
                            <option value="archived">Archived</option>
                        </select>
                        <InputError message={errors.status} className="mt-1" />
                    </div>
                </section>
            )}

            <div className="flex items-center justify-end gap-3">
                <Link href={route('risk.quantification.scenarios')}>
                    <SecondaryButton type="button">Cancel</SecondaryButton>
                </Link>
                <PrimaryButton disabled={processing}>
                    {isEdit ? 'Save changes' : 'Create scenario'}
                </PrimaryButton>
            </div>
        </form>
    );
}
