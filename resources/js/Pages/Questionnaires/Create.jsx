import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import { INPUT, SELECT, titleCase } from '../Campaigns/format';

/**
 * Start a questionnaire (Phase 4.5: risk/questionnaires/create.blade.php).
 *
 * `questionnaire_type` used to be validated `required` with no `in:` against a
 * six-value ENUM column, so a value the form did not send was a driver error
 * rather than a message. Both selects are driven from the enum now — the same
 * list the validator holds.
 */
export default function Create({ types = [], scoringMethods = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        questionnaire_type: types[0] ?? 'rcsa',
        scoring_method: scoringMethods[0] ?? 'average',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.questionnaires.store'));
    };

    return (
        <AuthenticatedLayout title="Create Questionnaire">
            <Head title="Create Questionnaire" />

            <PageHeader
                title="Create Questionnaire"
                subtitle="A reusable question set for assessment campaigns"
                breadcrumbs={[{ label: 'Questionnaires', href: route('risk.questionnaires.index') }, { label: 'Create' }]}
            />

            <form onSubmit={submit} className="max-w-2xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
                    <div>
                        <InputLabel htmlFor="title">Title<span className="text-red-500"> *</span></InputLabel>
                        <input
                            id="title"
                            type="text"
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            className={INPUT}
                            placeholder="e.g. Operational Risk RCSA Questionnaire"
                        />
                        <InputError message={errors.title} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="description">Description</InputLabel>
                        <textarea
                            id="description"
                            rows={3}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className={INPUT}
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <InputLabel htmlFor="questionnaire_type">Type<span className="text-red-500"> *</span></InputLabel>
                            <select
                                id="questionnaire_type"
                                value={data.questionnaire_type}
                                onChange={(e) => setData('questionnaire_type', e.target.value)}
                                className={SELECT}
                            >
                                {types.map((type) => (
                                    <option key={type} value={type}>{titleCase(type)}</option>
                                ))}
                            </select>
                            <InputError message={errors.questionnaire_type} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="scoring_method">Scoring Method<span className="text-red-500"> *</span></InputLabel>
                            <select
                                id="scoring_method"
                                value={data.scoring_method}
                                onChange={(e) => setData('scoring_method', e.target.value)}
                                className={SELECT}
                            >
                                {scoringMethods.map((method) => (
                                    <option key={method} value={method}>{titleCase(method)}</option>
                                ))}
                            </select>
                            <InputError message={errors.scoring_method} className="mt-1" />
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-between mt-6">
                    <Link href={route('risk.questionnaires.index')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Create
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
