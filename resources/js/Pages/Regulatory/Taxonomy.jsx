import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';

/** One node and everything under it. Recurses to whatever depth the tree has. */
function Node({ node, depth }) {
    return (
        <div className={depth > 0 ? 'border-l border-gray-200 pl-4 ml-3' : ''}>
            <div className="py-2">
                <div className="flex items-center gap-2">
                    {node.children.length > 0 ? (
                        <span className="material-symbols-outlined text-gray-400 text-sm">subdirectory_arrow_right</span>
                    ) : (
                        <span className="w-4 h-4 rounded-full bg-blue-100 flex items-center justify-center">
                            <span className="w-1.5 h-1.5 rounded-full bg-blue-500" />
                        </span>
                    )}
                    <span className="text-sm font-medium text-gray-800">{node.name}</span>
                    {node.framework && (
                        <span className="badge bg-gray-100 text-gray-500 text-[10px]">{node.framework}</span>
                    )}
                </div>
                {node.description && <p className="text-xs text-gray-400 ml-6">{node.description}</p>}
            </div>

            {node.children.map((child) => (
                <Node key={child.id} node={child} depth={depth + 1} />
            ))}
        </div>
    );
}

/**
 * The risk taxonomy (migration Phase 5.3).
 *
 * The tree arrives nested and complete. The Blade version eager-loaded
 * `children.children` — two levels — while its partial recursed to any depth,
 * so every node below the second level cost its own query; and its parent
 * picker ran a model query inside the template itself.
 *
 * `parent_id` reaches a tenant-bound Rule::exists in StoreTaxonomyRequest. It
 * had no rule at all: the id went into the column unchecked, so a node could be
 * parented under another institution's tree.
 */
export default function Taxonomy({ tree, parentOptions, frameworks, canManage }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        description: '',
        framework: '',
        parent_id: '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('risk.regulatory.store-taxonomy'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <AuthenticatedLayout title="Risk Taxonomy">
            <Head title="Risk Taxonomy" />

            <PageHeader
                title="Risk Taxonomy"
                subtitle="The framework tree this institution maps its register onto"
            />

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <section className="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-4">Taxonomy Tree</h3>

                    {tree.length === 0 ? (
                        <p className="text-gray-400 text-sm py-8 text-center">
                            No taxonomy nodes yet. Add one to start.
                        </p>
                    ) : (
                        tree.map((node) => <Node key={node.id} node={node} depth={0} />)
                    )}
                </section>

                {canManage && (
                    <section className="bg-white rounded-xl border border-gray-200 p-5">
                        <h3 className="text-sm font-semibold text-[#1A365D] mb-3">Add Taxonomy Node</h3>

                        <form onSubmit={submit} className="space-y-3">
                            <div>
                                <InputLabel htmlFor="name" value="Name" />
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
                                <InputLabel htmlFor="description" value="Description" />
                                <textarea
                                    id="description"
                                    rows={2}
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                />
                                <InputError message={errors.description} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="framework" value="Framework" />
                                <select
                                    id="framework"
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={data.framework}
                                    onChange={(e) => setData('framework', e.target.value)}
                                >
                                    <option value="">None</option>
                                    {frameworks.map((framework) => (
                                        <option key={framework} value={framework}>
                                            {framework}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.framework} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="parent_id" value="Parent node" />
                                <select
                                    id="parent_id"
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={data.parent_id}
                                    onChange={(e) => setData('parent_id', e.target.value)}
                                >
                                    <option value="">Root level</option>
                                    {parentOptions.map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {'— '.repeat(option.depth)}
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.parent_id} className="mt-1" />
                            </div>

                            <PrimaryButton disabled={processing} className="w-full justify-center">
                                Add node
                            </PrimaryButton>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
