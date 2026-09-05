import { useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmDialog from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { INPUT, SELECT, titleCase } from '../Campaigns/format';

/**
 * The questionnaire builder (Phase 4.5: risk/questionnaires/edit.blade.php).
 *
 * WHAT IS NO LONGER LOADED: the question library. The Blade controller grouped
 * the entire library by category and handed it to this page on every load, and
 * the template never referenced it — a dead query, of the same family as 3.8's
 * RCSA columns and 4.1's KRI properties. The library has its own screen; there
 * has never been a "copy from library" control here to feed.
 */
function AddSection({ questionnaire }) {
    const { data, setData, post, processing, errors, reset } = useForm({ title: '', description: '', weight: 1 });

    const submit = (e) => {
        e.preventDefault();
        post(questionnaire.addSectionUrl, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5">
            <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Add Section</h3>
            <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-6 gap-4 items-start">
                <div className="md:col-span-3">
                    <InputLabel htmlFor="section_title" className="text-xs">Section Title</InputLabel>
                    <input
                        id="section_title"
                        type="text"
                        value={data.title}
                        onChange={(e) => setData('title', e.target.value)}
                        className={INPUT}
                        placeholder="e.g. Operational Risk"
                    />
                    <InputError message={errors.title} className="mt-1" />
                </div>

                <div className="md:col-span-2">
                    <InputLabel htmlFor="section_weight" className="text-xs">Weight</InputLabel>
                    <input
                        id="section_weight"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={data.weight}
                        onChange={(e) => setData('weight', e.target.value)}
                        className={INPUT}
                    />
                    <InputError message={errors.weight} className="mt-1" />
                </div>

                <button type="submit" disabled={processing} className="btn-primary text-sm mt-6 disabled:opacity-50">
                    Add Section
                </button>
            </form>
        </div>
    );
}

function AddQuestion({ section, questionTypes }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        question_text: '',
        question_type: Object.keys(questionTypes)[0] ?? 'likert',
        is_required: true,
        help_text: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(section.addQuestionUrl, { preserveScroll: true, onSuccess: () => reset('question_text', 'help_text') });
    };

    return (
        <div className="px-5 py-4 border-t border-gray-100 bg-gray-50 rounded-b-xl">
            <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-6 gap-3 items-start">
                <div className="md:col-span-3">
                    <input
                        type="text"
                        value={data.question_text}
                        onChange={(e) => setData('question_text', e.target.value)}
                        className={INPUT.replace('mt-1 ', '')}
                        placeholder="Enter question…"
                        aria-label={`Question text for ${section.title}`}
                    />
                    <InputError message={errors.question_text} className="mt-1" />
                </div>

                <div>
                    <select
                        value={data.question_type}
                        onChange={(e) => setData('question_type', e.target.value)}
                        className={SELECT.replace('mt-1 ', '')}
                        aria-label={`Question type for ${section.title}`}
                    >
                        {Object.entries(questionTypes).map(([value, label]) => (
                            <option key={value} value={value}>{label}</option>
                        ))}
                    </select>
                    <InputError message={errors.question_type} className="mt-1" />
                </div>

                <label className="flex items-center gap-2 text-xs text-gray-600 mt-2">
                    <input
                        type="checkbox"
                        checked={data.is_required}
                        onChange={(e) => setData('is_required', e.target.checked)}
                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                    />
                    Required
                </label>

                <button type="submit" disabled={processing} className="btn-primary text-sm disabled:opacity-50">
                    Add Question
                </button>
            </form>
        </div>
    );
}

export default function Edit({ questionnaire, sections = [], questionTypes = {}, canPublish = false }) {
    const [publishing, setPublishing] = useState(false);
    const [removing, setRemoving] = useState(null);

    const publish = () => {
        router.post(questionnaire.publishUrl, {}, { preserveScroll: true });
        setPublishing(false);
    };

    const remove = () => {
        router.delete(removing.removeUrl, { preserveScroll: true });
        setRemoving(null);
    };

    return (
        <AuthenticatedLayout title="Edit Questionnaire">
            <Head title="Edit Questionnaire" />

            <PageHeader
                title={questionnaire.title}
                subtitle={`v${questionnaire.version} · ${titleCase(questionnaire.type)} · scored by ${titleCase(questionnaire.scoringMethod)}`}
                breadcrumbs={[
                    { label: 'Questionnaires', href: route('risk.questionnaires.index') },
                    { label: questionnaire.title },
                ]}
                actions={
                    <>
                        <StatusBadge status={questionnaire.status} />
                        {canPublish && questionnaire.status === 'draft' && (
                            <button
                                type="button"
                                onClick={() => setPublishing(true)}
                                className="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-medium hover:bg-green-700"
                            >
                                Publish
                            </button>
                        )}
                    </>
                }
            />

            <div className="space-y-6">
                <AddSection questionnaire={questionnaire} />

                {sections.map((section) => (
                    <div key={section.id} className="bg-white rounded-xl border border-gray-200">
                        <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                            <h3 className="text-sm font-semibold text-[#1A365D]">
                                {section.title}
                                <span className="text-xs text-gray-400 ml-2">Weight: {section.weight}</span>
                            </h3>
                            <span className="text-xs text-gray-500">
                                {section.questions.length} question{section.questions.length === 1 ? '' : 's'}
                            </span>
                        </div>

                        <div className="px-5 py-3 space-y-2">
                            {section.questions.length === 0 && (
                                <p className="text-sm text-gray-400 py-2">No questions in this section yet.</p>
                            )}
                            {section.questions.map((question, index) => (
                                <div
                                    key={question.id}
                                    className={`flex items-center justify-between py-2 ${index < section.questions.length - 1 ? 'border-b border-gray-50' : ''}`}
                                >
                                    <div>
                                        <p className="text-sm text-gray-800">{question.text}</p>
                                        <p className="text-xs text-gray-400">
                                            {titleCase(question.type)}{question.isRequired ? ' (Required)' : ''}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setRemoving(question)}
                                        className="text-red-400 hover:text-red-600"
                                        aria-label={`Remove question: ${question.text}`}
                                    >
                                        <span className="material-symbols-outlined text-lg">delete</span>
                                    </button>
                                </div>
                            ))}
                        </div>

                        <AddQuestion section={section} questionTypes={questionTypes} />
                    </div>
                ))}

                {sections.length === 0 && (
                    <div className="text-center py-12 text-gray-400">
                        <span className="material-symbols-outlined text-4xl mb-2 block">quiz</span>
                        <p className="text-sm">Add sections above to start building your questionnaire.</p>
                    </div>
                )}

                <div className="flex justify-end">
                    <Link href={route('risk.questionnaires.show', questionnaire.id)} className="btn-secondary text-sm">
                        Preview
                    </Link>
                </div>
            </div>

            <ConfirmDialog
                show={publishing}
                title="Publish this questionnaire?"
                message="A published questionnaire can be attached to a campaign and answered. Its questions should not change once respondents have started."
                confirmLabel="Publish"
                variant="success"
                onConfirm={publish}
                onCancel={() => setPublishing(false)}
            />

            <ConfirmDialog
                show={removing !== null}
                title="Remove this question?"
                message={removing ? `“${removing.text}” will be deleted from this questionnaire.` : ''}
                confirmLabel="Remove"
                onConfirm={remove}
                onCancel={() => setRemoving(null)}
            />
        </AuthenticatedLayout>
    );
}
