import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { titleCase } from '../Campaigns/format';

/**
 * A questionnaire as a respondent will meet it (Phase 4.5:
 * risk/questionnaires/show.blade.php).
 *
 * Read-only. The Blade page had no breadcrumbs and no way back to the register;
 * both are here, and the version and status now show, because "which version of
 * this did we send out" is the first question anybody asks of a question set.
 */
export default function Show({ questionnaire, sections = [] }) {
    const questionCount = sections.reduce((total, section) => total + section.questions.length, 0);

    return (
        <AuthenticatedLayout title={`Questionnaire: ${questionnaire.title}`}>
            <Head title={`Questionnaire: ${questionnaire.title}`} />

            <PageHeader
                title={questionnaire.title}
                subtitle={`v${questionnaire.version} · ${titleCase(questionnaire.type)} · ${sections.length} section${sections.length === 1 ? '' : 's'} · ${questionCount} question${questionCount === 1 ? '' : 's'}`}
                breadcrumbs={[
                    { label: 'Questionnaires', href: route('risk.questionnaires.index') },
                    { label: questionnaire.title },
                ]}
                actions={
                    <>
                        <StatusBadge status={questionnaire.status} />
                        <Link href={questionnaire.editUrl} className="btn-secondary text-sm">Edit</Link>
                    </>
                }
            />

            <div className="max-w-3xl space-y-6">
                {questionnaire.description && (
                    <p className="text-sm text-gray-600">{questionnaire.description}</p>
                )}

                {sections.length === 0 && (
                    <EmptyState
                        icon={<span className="material-symbols-outlined text-3xl text-gray-400">quiz</span>}
                        title="No sections yet"
                        description="A questionnaire needs at least one section before it can be published."
                        actionLabel="Add sections"
                        actionHref={questionnaire.editUrl}
                    />
                )}

                {sections.map((section) => (
                    <div key={section.id} className="bg-white rounded-xl border border-gray-200 p-6">
                        <h3 className="text-sm font-semibold text-[#1A365D]">{section.title}</h3>
                        {section.description && <p className="text-xs text-gray-500 mt-1">{section.description}</p>}

                        <div className="mt-4">
                            {section.questions.length === 0 && (
                                <p className="text-sm text-gray-400">This section has no questions.</p>
                            )}
                            {section.questions.map((question, index) => (
                                <div key={question.id} className={index > 0 ? 'py-3 border-t border-gray-50' : 'pb-3'}>
                                    <p className="text-sm text-gray-800">
                                        {index + 1}. {question.text}
                                        {question.isRequired && <span className="text-red-500"> *</span>}
                                    </p>
                                    <p className="text-xs text-gray-400 mt-1">{titleCase(question.type)}</p>
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
