import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { number } from '@/Components/Quantification/figures';

/**
 * Quantification settings (migration Phase 5.2).
 *
 * FIVE FIELDS, ALL OF WHICH ROUND-TRIP. This screen used to offer ten and
 * store three: `default_confidence`, `default_time_horizon`, `seed`,
 * `target_car`, `countercyclical_buffer` and a green/amber/red CAR band had no
 * column anywhere, so a preparer typed them, the screen said "settings have
 * been updated", and every one was discarded — two of them `required`, so they
 * had to be filled in on every save to be thrown away. The reasoning for what
 * was kept and what was deleted is on QuantificationSettingsService.
 */
export default function Settings({ settings, iterationChoices, horizonChoices, confidenceChoices, minimumCarGuidance }) {
    const { data, setData, put, processing, errors } = useForm({
        default_iterations: settings.default_iterations,
        default_horizon_years: settings.default_horizon_years,
        default_confidence_levels: settings.default_confidence_levels,
        cbn_minimum_car: settings.cbn_minimum_car,
        cbn_conservation_buffer: settings.cbn_conservation_buffer,
    });

    const toggleLevel = (level) => {
        const current = data.default_confidence_levels;
        setData(
            'default_confidence_levels',
            current.includes(level) ? current.filter((l) => l !== level) : [...current, level],
        );
    };

    const submit = (event) => {
        event.preventDefault();
        put(route('risk.quantification.update-settings'));
    };

    return (
        <AuthenticatedLayout title="Quantification Settings">
            <Head title="Quantification Settings" />

            <PageHeader
                title="Quantification Settings"
                subtitle="Simulation defaults and the CBN capital parameters this institution is measured against"
            />

            <form onSubmit={submit} className="space-y-6">
                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-2">
                        <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">1</div>
                        <h2 className="text-lg font-semibold text-[#1A365D]">Simulation Defaults</h2>
                    </div>
                    <p className="text-xs text-gray-500 mb-6">
                        The values the simulation setup form opens with. Any individual run may still override them.
                    </p>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <InputLabel htmlFor="default_iterations" value="Default iterations" />
                            <select
                                id="default_iterations"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={data.default_iterations}
                                onChange={(e) => setData('default_iterations', Number(e.target.value))}
                            >
                                {iterationChoices.map((iterations) => (
                                    <option key={iterations} value={iterations}>
                                        {number(iterations)}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.default_iterations} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="default_horizon_years" value="Default time horizon" />
                            <select
                                id="default_horizon_years"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={data.default_horizon_years}
                                onChange={(e) => setData('default_horizon_years', Number(e.target.value))}
                            >
                                {horizonChoices.map((years) => (
                                    <option key={years} value={years}>
                                        {years} Year{years > 1 ? 's' : ''}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.default_horizon_years} className="mt-1" />
                        </div>

                        <div className="lg:col-span-2">
                            <InputLabel value="Default confidence levels" />
                            <div className="flex flex-wrap gap-4 mt-2">
                                {confidenceChoices.map((level) => (
                                    <label key={level} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                            checked={data.default_confidence_levels.includes(level)}
                                            onChange={() => toggleLevel(level)}
                                        />
                                        <span className="text-sm text-gray-700">{level}%</span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors.default_confidence_levels} className="mt-1" />
                        </div>
                    </div>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-2">
                        <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">2</div>
                        <h2 className="text-lg font-semibold text-[#1A365D]">CBN Capital Parameters</h2>
                    </div>
                    <p className="text-xs text-gray-500 mb-6">
                        The minimum CAR an ICAAP assessment is reconciled against when the assessment does not carry its
                        own. The CBN sets {minimumCarGuidance.national}% for a national or regional authorisation and{' '}
                        {minimumCarGuidance.international}% for an international authorisation or a D-SIB designation.
                        Nothing here infers which applies to this institution.
                    </p>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <InputLabel htmlFor="cbn_minimum_car" value="Minimum CAR (%)" />
                            <TextInput
                                id="cbn_minimum_car"
                                type="number"
                                step="0.1"
                                min="0"
                                max="100"
                                className="mt-1 block w-full"
                                value={data.cbn_minimum_car}
                                onChange={(e) => setData('cbn_minimum_car', e.target.value)}
                                required
                            />
                            <InputError message={errors.cbn_minimum_car} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="cbn_conservation_buffer" value="Capital conservation buffer (%)" />
                            <TextInput
                                id="cbn_conservation_buffer"
                                type="number"
                                step="0.1"
                                min="0"
                                max="100"
                                className="mt-1 block w-full"
                                value={data.cbn_conservation_buffer}
                                onChange={(e) => setData('cbn_conservation_buffer', e.target.value)}
                                required
                            />
                            <InputError message={errors.cbn_conservation_buffer} className="mt-1" />
                        </div>
                    </div>
                </section>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.quantification.dashboard')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={processing}>Save settings</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
