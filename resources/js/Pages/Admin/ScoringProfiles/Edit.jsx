import { useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';

const DIMENSION_LABELS = {
    financial: 'Financial',
    operational: 'Operational',
    reputational: 'Reputational',
    regulatory: 'Regulatory',
    strategic: 'Strategic',
};

const AGGREGATION_LABELS = {
    max: 'Worst dimension wins',
    average: 'Mean of the scored dimensions',
    weighted: 'Weighted mean',
    worst_two: 'Mean of the two highest',
};

function Section({ title, hint, children }) {
    return (
        <section className="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
            <div>
                <h2 className="text-sm font-semibold text-[#1A365D]">{title}</h2>
                {hint && <p className="text-xs text-gray-500 mt-1 max-w-2xl">{hint}</p>}
            </div>
            {children}
        </section>
    );
}

function ScaleEditor({ title, hint, scale, onChange, errors, prefix }) {
    return (
        <div>
            <p className="text-sm font-medium text-gray-700">{title}</p>
            <p className="text-xs text-gray-500 mb-3">{hint}</p>
            <div className="space-y-2">
                {scale.map((level, index) => (
                    <div key={level.value ?? index} className="flex flex-col sm:flex-row gap-2 items-start">
                        <span className="w-8 pt-2 text-xs font-mono text-gray-500 shrink-0">{level.value}</span>
                        <div className="flex-1">
                            <TextInput
                                className="block w-full text-sm"
                                value={level.label ?? ''}
                                onChange={(e) => onChange(index, 'label', e.target.value)}
                                placeholder="Label"
                            />
                            <InputError message={errors[`${prefix}.${index}.label`]} className="mt-1" />
                        </div>
                        <div className="flex-[2]">
                            <TextInput
                                className="block w-full text-sm"
                                value={level.definition ?? ''}
                                onChange={(e) => onChange(index, 'definition', e.target.value)}
                                placeholder="What this level means"
                            />
                            <InputError message={errors[`${prefix}.${index}.definition`]} className="mt-1" />
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * The scoring profile editor (migration Phase 6.4).
 *
 * Two things this screen does that a plain CRUD form would not.
 *
 * It PREVIEWS THE RE-RATING before saving. Changing a band boundary re-rates
 * every risk in the register, and the operator is shown how many move and in
 * which direction while the change is still abandonable. The Livewire screen
 * recomputed that on every render; here the page asks an endpoint when the
 * bands settle, which is the same guarantee and one query instead of one per
 * keystroke.
 *
 * It CHECKS THE RESIDUAL FORMULA. A formula that cannot be evaluated does not
 * fail loudly — RiskScoringService logs a warning and falls back to the
 * platform default — so a typo silently reverts every residual score in the
 * register. The server evaluates it with a worked example, here and again on
 * save.
 */
export default function Edit({ profile, template, options }) {
    const isEdit = profile !== null;
    const seed = profile ?? template;

    const form = useForm({
        name: profile?.name ?? '',
        code: profile?.code ?? '',
        description: profile?.description ?? '',
        matrix_rows: profile?.matrix_rows ?? 5,
        matrix_cols: profile?.matrix_cols ?? 5,
        impact_aggregation: profile?.impact_aggregation ?? 'max',
        residual_formula: profile?.residual_formula ?? options.defaultFormula,
        effective_from: profile?.effective_from ?? '',
        is_default: Boolean(profile?.is_default),
        likelihood_scale: seed.likelihood_scale ?? [],
        impact_scale: seed.impact_scale ?? [],
        rating_bands: seed.rating_bands ?? [],
        impact_dimensions: seed.impact_dimensions ?? options.dimensions,
        dimension_weights: seed.dimension_weights ?? {},
        applies_to_object_type_ids: profile?.applies_to_object_type_ids ?? [],
        applies_to_risk_types: profile?.applies_to_risk_types ?? [],
    });

    const [formulaCheck, setFormulaCheck] = useState(null);
    const [preview, setPreview] = useState(null);

    const maxScore = form.data.matrix_rows * form.data.matrix_cols;

    // Ask for the re-rating preview when the bands or the matrix settle, not on
    // every keystroke.
    useEffect(() => {
        const handle = setTimeout(() => {
            fetch(route('admin.scoring-profiles.preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({
                    matrix_rows: form.data.matrix_rows,
                    matrix_cols: form.data.matrix_cols,
                    rating_bands: form.data.rating_bands,
                }),
            })
                .then((response) => (response.ok ? response.json() : null))
                .then(setPreview)
                .catch(() => setPreview(null));
        }, 600);

        return () => clearTimeout(handle);
    }, [form.data.rating_bands, form.data.matrix_rows, form.data.matrix_cols]);

    const checkFormula = () => {
        fetch(route('admin.scoring-profiles.validate-formula'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                formula: form.data.residual_formula,
                matrix_rows: form.data.matrix_rows,
                matrix_cols: form.data.matrix_cols,
            }),
        })
            .then((response) => response.json())
            .then(setFormulaCheck)
            .catch(() => setFormulaCheck(null));
    };

    const setLevel = (field) => (index, key, value) =>
        form.setData(
            field,
            form.data[field].map((level, i) => (i === index ? { ...level, [key]: value } : level)),
        );

    const setBand = (index, key, value) =>
        form.setData(
            'rating_bands',
            form.data.rating_bands.map((band, i) => (i === index ? { ...band, [key]: value } : band)),
        );

    const addBand = () => {
        const last = form.data.rating_bands[form.data.rating_bands.length - 1];
        const min = (last ? Number(last.max) : 0) + 1;

        form.setData('rating_bands', [
            ...form.data.rating_bands,
            {
                code: `band_${form.data.rating_bands.length + 1}`,
                label: 'New band',
                color: '#64748b',
                min,
                max: Math.max(min, maxScore),
            },
        ]);
    };

    const toggleDimension = (dimension) =>
        form.setData(
            'impact_dimensions',
            form.data.impact_dimensions.includes(dimension)
                ? form.data.impact_dimensions.filter((d) => d !== dimension)
                : [...form.data.impact_dimensions, dimension],
        );

    const toggleType = (id) =>
        form.setData(
            'applies_to_object_type_ids',
            form.data.applies_to_object_type_ids.includes(id)
                ? form.data.applies_to_object_type_ids.filter((v) => v !== id)
                : [...form.data.applies_to_object_type_ids, id],
        );

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(route('admin.scoring-profiles.update', profile.id));
        } else {
            form.post(route('admin.scoring-profiles.store'));
        }
    };

    const bandErrors = Object.entries(form.errors).filter(([key]) => key.startsWith('rating_bands'));

    return (
        <AuthenticatedLayout title={isEdit ? `Edit ${profile.name}` : 'New scoring profile'}>
            <Head title={isEdit ? `Edit ${profile.name}` : 'New scoring profile'} />

            <PageHeader
                title={isEdit ? profile.name : 'New scoring profile'}
                subtitle="What a score means for this organisation"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Scoring profiles', href: route('admin.builder.scoring-profiles') },
                    { label: isEdit ? profile.name : 'New' },
                ]}
            />

            {isEdit && profile.is_system && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    This is the seeded profile — what every organisation without one of their own scores against. Saving
                    copies it into this organisation rather than changing it for everybody.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Section title="Identity">
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="code" value="Code" />
                            <TextInput
                                id="code"
                                className="mt-1 block w-full font-mono text-sm"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.code} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="effective_from" value="Effective from" />
                            <TextInput
                                id="effective_from"
                                type="date"
                                className="mt-1 block w-full"
                                value={form.data.effective_from ?? ''}
                                onChange={(e) => form.setData('effective_from', e.target.value)}
                            />
                            <InputError message={form.errors.effective_from} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <textarea
                            id="description"
                            rows={2}
                            className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.description ?? ''}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={form.data.is_default}
                            onChange={(e) => form.setData('is_default', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Use this profile by default
                            <span className="block text-xs text-gray-500">
                                Exactly one profile is the default; setting this clears the others.
                            </span>
                        </span>
                    </label>
                </Section>

                <Section
                    title="The matrix"
                    hint="Between 3 and 10 on each axis. Below 3 a matrix cannot distinguish anything; above 10 it is unreadable."
                >
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <InputLabel htmlFor="matrix_rows" value="Likelihood levels" />
                            <TextInput
                                id="matrix_rows"
                                type="number"
                                min="3"
                                max="10"
                                className="mt-1 block w-full"
                                value={form.data.matrix_rows}
                                onChange={(e) => form.setData('matrix_rows', Number(e.target.value))}
                            />
                            <InputError message={form.errors.matrix_rows} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor="matrix_cols" value="Impact levels" />
                            <TextInput
                                id="matrix_cols"
                                type="number"
                                min="3"
                                max="10"
                                className="mt-1 block w-full"
                                value={form.data.matrix_cols}
                                onChange={(e) => form.setData('matrix_cols', Number(e.target.value))}
                            />
                            <InputError message={form.errors.matrix_cols} className="mt-1" />
                        </div>
                        <div className="flex items-end pb-2">
                            <p className="text-sm text-gray-600">
                                Top attainable score: <span className="font-semibold">{maxScore}</span>
                            </p>
                        </div>
                    </div>

                    <ScaleEditor
                        title="Likelihood scale"
                        hint="What each point on the likelihood axis means to this organisation."
                        scale={form.data.likelihood_scale}
                        onChange={setLevel('likelihood_scale')}
                        errors={form.errors}
                        prefix="likelihood_scale"
                    />

                    <ScaleEditor
                        title={`Impact scale (${options.currency})`}
                        hint="What each point on the impact axis means — the band of loss it stands for."
                        scale={form.data.impact_scale}
                        onChange={setLevel('impact_scale')}
                        errors={form.errors}
                        prefix="impact_scale"
                    />
                </Section>

                <Section
                    title="Rating bands"
                    hint={`Every score from 1 to ${maxScore} must fall into exactly one band. A score in no band renders as a blank rating, which on a dashboard is indistinguishable from "not assessed".`}
                >
                    {bandErrors.length > 0 && (
                        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 space-y-1">
                            {bandErrors.map(([key, message]) => (
                                <p key={key} className="text-sm text-red-800">
                                    {message}
                                </p>
                            ))}
                        </div>
                    )}

                    <div className="space-y-2">
                        {form.data.rating_bands.map((band, index) => (
                            <div key={index} className="flex flex-col sm:flex-row gap-2 items-start">
                                <TextInput
                                    className="flex-1 text-sm"
                                    value={band.label ?? ''}
                                    onChange={(e) => setBand(index, 'label', e.target.value)}
                                    placeholder="Label"
                                />
                                <TextInput
                                    className="sm:w-40 text-sm font-mono"
                                    value={band.code ?? ''}
                                    onChange={(e) => setBand(index, 'code', e.target.value)}
                                    placeholder="code"
                                />
                                <TextInput
                                    type="number"
                                    min="0"
                                    className="sm:w-24 text-sm"
                                    value={band.min}
                                    onChange={(e) => setBand(index, 'min', Number(e.target.value))}
                                />
                                <TextInput
                                    type="number"
                                    min="0"
                                    className="sm:w-24 text-sm"
                                    value={band.max}
                                    onChange={(e) => setBand(index, 'max', Number(e.target.value))}
                                />
                                <input
                                    type="color"
                                    className="sm:w-16 h-10 border-gray-300 rounded-md"
                                    value={band.color || '#64748b'}
                                    onChange={(e) => setBand(index, 'color', e.target.value)}
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData(
                                            'rating_bands',
                                            form.data.rating_bands.filter((_, i) => i !== index),
                                        )
                                    }
                                    className="text-xs text-red-600 font-medium hover:opacity-80 px-2 py-2.5"
                                >
                                    Remove
                                </button>
                            </div>
                        ))}
                    </div>

                    <button
                        type="button"
                        onClick={addBand}
                        className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                    >
                        + Add a band
                    </button>

                    {preview && (
                        <div
                            className={`rounded-lg border p-4 ${
                                preview.moved > 0
                                    ? 'border-amber-200 bg-amber-50 text-amber-900'
                                    : 'border-gray-200 bg-gray-50 text-gray-700'
                            }`}
                        >
                            <p className="text-sm font-semibold">
                                {preview.moved === 0
                                    ? `No risks change band. ${preview.total} assessed risk(s) checked.`
                                    : `${preview.moved} of ${preview.total} assessed risks change band if you save this.`}
                            </p>
                            {preview.examples?.length > 0 && (
                                <ul className="mt-2 text-xs space-y-0.5 font-mono">
                                    {preview.examples.map((example) => (
                                        <li key={example}>{example}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </Section>

                <Section
                    title="Impact dimensions"
                    hint="Which dimensions are scored, and how they collapse into the single impact score that drives the rating."
                >
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        {options.dimensions.map((dimension) => (
                            <label
                                key={dimension}
                                className="flex items-center gap-2 p-2 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                    checked={form.data.impact_dimensions.includes(dimension)}
                                    onChange={() => toggleDimension(dimension)}
                                />
                                <span className="text-sm text-gray-700">
                                    {DIMENSION_LABELS[dimension] ?? dimension}
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={form.errors.impact_dimensions} className="mt-1" />

                    <div className="sm:w-1/2">
                        <InputLabel htmlFor="impact_aggregation" value="How they collapse" />
                        <select
                            id="impact_aggregation"
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.impact_aggregation}
                            onChange={(e) => form.setData('impact_aggregation', e.target.value)}
                        >
                            {options.aggregations.map((value) => (
                                <option key={value} value={value}>
                                    {AGGREGATION_LABELS[value] ?? value}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.impact_aggregation} className="mt-1" />
                    </div>

                    {form.data.impact_aggregation === 'weighted' && (
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            {form.data.impact_dimensions.map((dimension) => (
                                <div key={dimension}>
                                    <InputLabel value={DIMENSION_LABELS[dimension] ?? dimension} />
                                    <TextInput
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.1"
                                        className="mt-1 block w-full"
                                        value={form.data.dimension_weights[dimension] ?? ''}
                                        onChange={(e) =>
                                            form.setData('dimension_weights', {
                                                ...form.data.dimension_weights,
                                                [dimension]: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={form.errors[`dimension_weights.${dimension}`]}
                                        className="mt-1"
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                </Section>

                <Section
                    title="Residual formula"
                    hint="How a residual score follows from the inherent score and how effective the controls are. `inherent`, `effectiveness` and `max_score` are in scope."
                >
                    <div className="flex flex-col sm:flex-row gap-2 items-start">
                        <div className="flex-1">
                            <TextInput
                                className="block w-full font-mono text-sm"
                                value={form.data.residual_formula ?? ''}
                                onChange={(e) => {
                                    form.setData('residual_formula', e.target.value);
                                    setFormulaCheck(null);
                                }}
                            />
                            <InputError message={form.errors.residual_formula} className="mt-1" />
                        </div>
                        <SecondaryButton type="button" onClick={checkFormula}>
                            Check
                        </SecondaryButton>
                    </div>

                    {formulaCheck && (
                        <p
                            className={`text-sm rounded-lg border px-4 py-3 ${
                                formulaCheck.valid
                                    ? 'border-green-200 bg-green-50 text-green-800'
                                    : 'border-red-200 bg-red-50 text-red-800'
                            }`}
                        >
                            {formulaCheck.message}
                        </p>
                    )}
                </Section>

                <Section
                    title="Where it applies"
                    hint="Leave both empty to apply to everything this organisation scores."
                >
                    <div>
                        <p className="text-sm font-medium text-gray-700 mb-2">Object types</p>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 max-h-40 overflow-y-auto">
                            {options.types.map((type) => (
                                <label
                                    key={type.id}
                                    className="flex items-center gap-2 p-2 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={form.data.applies_to_object_type_ids.includes(type.id)}
                                        onChange={() => toggleType(type.id)}
                                    />
                                    <span className="text-xs text-gray-700">{type.name}</span>
                                </label>
                            ))}
                        </div>
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('applies_to_object_type_ids'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
                    </div>

                    <div>
                        <InputLabel htmlFor="applies_to_risk_types" value="Risk types" />
                        <TextInput
                            id="applies_to_risk_types"
                            className="mt-1 block w-full"
                            value={form.data.applies_to_risk_types.join(', ')}
                            onChange={(e) =>
                                form.setData(
                                    'applies_to_risk_types',
                                    e.target.value
                                        .split(',')
                                        .map((item) => item.trim())
                                        .filter(Boolean),
                                )
                            }
                            placeholder="Comma separated"
                        />
                    </div>
                </Section>

                <div className="flex items-center justify-end gap-3 pb-8">
                    <Link href={route('admin.builder.scoring-profiles')}>
                        <SecondaryButton type="button">Cancel</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={form.processing}>
                        {isEdit && profile.is_system ? 'Save as our own profile' : 'Save profile'}
                    </PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
