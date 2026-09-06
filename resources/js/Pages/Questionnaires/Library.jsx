import { Head, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import InputError from '@thirdline/ui/Components/InputError';
import PageHeader from '@thirdline/ui/Components/PageHeader';

const QUESTION_TYPES = [
    ['likert', 'Likert'],
    ['rating', 'Rating'],
    ['yes_no', 'Yes/No'],
    ['free_text', 'Free Text'],
];

/**
 * The question library (migration Phase 2) — see
 * App\Grids\Definitions\QuestionLibraryGrid. The add-to-library form posts
 * through Inertia: its redirect lands back on this page.
 */
export default function Library({ total, grid }) {
    const permissions = usePage().props.auth?.permissions ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        question_text: '',
        category: '',
        question_type: 'likert',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.questionnaires.store-library'), { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <AuthenticatedLayout title="Question Library">
            <Head title="Question Library" />

            <PageHeader title="Question Library" subtitle={`${total} ${total === 1 ? 'question' : 'questions'} available to reuse`} />

            {permissions.includes('questionnaire.create') && (
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
                    <form onSubmit={submit} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div className="md:col-span-2">
                            <label className="text-xs text-gray-500" htmlFor="question_text">Question</label>
                            <input
                                id="question_text"
                                type="text"
                                value={data.question_text}
                                onChange={(e) => setData('question_text', e.target.value)}
                                required
                                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                            />
                            <InputError message={errors.question_text} className="mt-1" />
                        </div>
                        <div>
                            <label className="text-xs text-gray-500" htmlFor="category">Category</label>
                            <input
                                id="category"
                                type="text"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                required
                                placeholder="e.g., Operational Risk"
                                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                            />
                            <InputError message={errors.category} className="mt-1" />
                        </div>
                        <div>
                            <label className="text-xs text-gray-500" htmlFor="question_type">Type</label>
                            <select
                                id="question_type"
                                value={data.question_type}
                                onChange={(e) => setData('question_type', e.target.value)}
                                className="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm"
                            >
                                {QUESTION_TYPES.map(([value, label]) => (
                                    <option key={value} value={value}>{label}</option>
                                ))}
                            </select>
                            <InputError message={errors.question_type} className="mt-1" />
                        </div>
                        <button type="submit" disabled={processing} className="btn-primary text-sm">
                            Add to Library
                        </button>
                    </form>
                </div>
            )}

            <DataGrid grid={grid} />
        </AuthenticatedLayout>
    );
}
