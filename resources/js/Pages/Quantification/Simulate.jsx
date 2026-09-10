import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import { naira, number } from '@/Components/Quantification/figures';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Configure and launch a Monte Carlo run (migration Phase 5.2).
 *
 * The form opens on the organisation's configured defaults — the reason the
 * settings screen exists, and something it could not do while this page
 * hardcoded 10,000 iterations, a one-year horizon and 95/99/99.5.
 *
 * Each scenario in the picker states its real calibration. The Blade version
 * read `risk_category`, `distribution_type` and `mean` off the model, where
 * none of them are columns, so every row read "· · Mean: ₦0" and an operator
 * assembling a capital run had nothing to choose on.
 */
export default function Simulate({ scenarios, defaults, iterationChoices, horizonChoices, confidenceChoices }) {
    const now = new Date();
    const stamp = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const ITERATION_LABELS = {
        1000: 'Quick',
        10000: 'Standard',
        50000: 'High precision',
        100000: 'Maximum precision',
    };

    const { data, setData, post, processing, errors } = useForm({
        name: `Simulation - ${stamp}`,
        iterations: defaults.iterations,
        time_horizon: defaults.horizon_years,
        confidence_levels: defaults.confidence_levels,
        scenario_ids: [],
    });

    const toggle = (key, value) => {
        const current = data[key];
        setData(key, current.includes(value) ? current.filter((v) => v !== value) : [...current, value]);
    };

    const submit = (event) => {
        event.preventDefault();
        post(route('risk.quantification.run-simulation'));
    };

    const createUrl = tryRoute('risk.quantification.create-scenario');

    return (
        <AuthenticatedLayout title="Configure Simulation">
            <Head title="Configure Simulation" />

            <PageHeader
                title="Configure Monte Carlo Simulation"
                subtitle="Set up simulation parameters and select the scenarios to include"
            />

            <form onSubmit={submit} className="space-y-6">
                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-6">
                        <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                        <h2 className="text-lg font-semibold text-[#1A365D]">Simulation Configuration</h2>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <InputLabel htmlFor="name" value="Simulation name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                            <InputError message={errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="iterations" value="Number of iterations" />
                            <select
                                id="iterations"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={data.iterations}
                                onChange={(e) => setData('iterations', Number(e.target.value))}
                                required
                            >
                                {iterationChoices.map((iterations) => (
                                    <option key={iterations} value={iterations}>
                                        {number(iterations)}
                                        {ITERATION_LABELS[iterations] ? ` (${ITERATION_LABELS[iterations]})` : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.iterations} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="time_horizon" value="Time horizon" />
                            <select
                                id="time_horizon"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={data.time_horizon}
                                onChange={(e) => setData('time_horizon', Number(e.target.value))}
                                required
                            >
                                {horizonChoices.map((years) => (
                                    <option key={years} value={years}>
                                        {years} Year{years > 1 ? 's' : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.time_horizon} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel value="Confidence levels" />
                            <div className="flex flex-wrap gap-3 mt-2">
                                {confidenceChoices.map((level) => (
                                    <label key={level} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={data.confidence_levels.includes(level)}
                                            onChange={() => toggle('confidence_levels', level)}
                                        />
                                        <span className="text-sm text-gray-700">{level}%</span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.confidence_levels} className="mt-1" />
                        </div>
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-2">
                        <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                        <h2 className="text-lg font-semibold text-[#1A365D]">Select Scenarios</h2>
                    </div>
                    <p className="text-sm text-gray-500 mb-4">Choose which risk scenarios to include in this simulation</p>

                    {scenarios.length === 0 ? (
                        <EmptyState
                            icon={<span className="material-symbols-outlined text-3xl text-gray-400">category</span>}
                            title="No active scenarios"
                            description="A simulation draws on calibrated scenarios. Create one, or import a template from the library."
                            actionLabel={createUrl ? 'Create a scenario' : undefined}
                            actionHref={createUrl ?? undefined}
                        />
                    ) : (
                        <div className="space-y-3">
                            {scenarios.map((scenario) => (
                                <label
                                    key={scenario.id}
                                    className="flex items-center gap-4 p-4 bg-gray-50 rounded-lg cursor-pointer hover:bg-blue-50 transition-colors"
                                >
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={data.scenario_ids.includes(scenario.id)}
                                        onChange={() => toggle('scenario_ids', scenario.id)}
                                    />
                                    <div className="flex-1">
                                        <p className="text-sm font-medium text-gray-700">
                                            <span className="text-xs text-gray-400 mr-2">{scenario.scenario_reference}</span>
                                            {scenario.name}
                                        </p>
                                        <p className="text-xs text-gray-500 mt-0.5">
                                            {scenario.risk_category ?? 'No category'} &middot; mean{' '}
                                            {naira(scenario.mean) ?? 'not recorded'} &middot;{' '}
                                            {scenario.frequency_per_year === null
                                                ? 'frequency not recorded'
                                                : `${number(scenario.frequency_per_year, 2)} events/year`}
                                        </p>
                                    </div>
                                    <span className="badge bg-blue-100 text-blue-700">{scenario.distribution_type ?? '—'}</span>
                                </label>
                            ))}
                        </div>
                    )}

                    <InputError message={errors.scenario_ids} className="mt-2" />
                </section>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.quantification.dashboard')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={processing || scenarios.length === 0}>
                        <span className="material-symbols-outlined text-lg mr-2">play_arrow</span> Run simulation
                    </PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
