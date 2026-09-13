import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import Modal from '@thirdline/ui/Components/Modal';

const CATEGORY_LABELS = {
    org_node: 'Organisation node',
    governance: 'Governance',
    assessment: 'Assessment',
    reference: 'Reference',
};

const blank = {
    code: '',
    name: '',
    plural_name: '',
    description: '',
    category: 'governance',
    parent_type_id: '',
    icon: 'category',
    color: '#1A365D',
    is_node_type: false,
    allowed_child_type_ids: [],
    default_lifecycle_id: '',
    code_prefix: '',
    sort_order: 0,
};

const studly = (value) =>
    value
        .split(/[^A-Za-z0-9]+/)
        .filter(Boolean)
        .map((word) => word[0].toUpperCase() + word.slice(1))
        .join('');

/**
 * The object type registry (migration Phase 6.3).
 *
 * A SYSTEM TYPE IS EDITABLE BUT NOT DELETABLE, AND ONLY COSMETICALLY. The
 * seeded registry is resolved by code from a dozen places in the platform, so
 * the code, category and node flag are locked on one and the presentation
 * fields — icon, colour, reference prefix — stay open, which is what a tenant
 * actually wants. `can_edit_identity` and `can_delete` are the server's answers
 * (ObjectTypePolicy), not this page's guesses.
 */
export default function Index({ types, filters, options }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const form = useForm({ ...blank });
    const isEdit = editing !== null && editing.id !== undefined;
    const identityLocked = isEdit && !editing.can_edit_identity;

    const openCreate = () => {
        form.setData({ ...blank });
        form.clearErrors();
        setEditing({});
    };

    const openEdit = (type) => {
        form.setData({
            code: type.code ?? '',
            name: type.name ?? '',
            plural_name: type.plural_name ?? '',
            description: type.description ?? '',
            category: type.category ?? 'governance',
            parent_type_id: type.parent_type_id ?? '',
            icon: type.icon ?? 'category',
            color: type.color ?? '#1A365D',
            is_node_type: Boolean(type.is_node_type),
            allowed_child_type_ids: type.allowed_child_type_ids ?? [],
            default_lifecycle_id: type.default_lifecycle_id ?? '',
            code_prefix: type.code_prefix ?? '',
            sort_order: type.sort_order ?? 0,
        });
        form.clearErrors();
        setEditing(type);
    };

    // Only while creating: renaming an existing type must never move its code,
    // because the code is what everything else resolves it by.
    const setName = (value) => {
        form.setData((data) => ({
            ...data,
            name: value,
            code: !isEdit && data.code === '' ? studly(value) : data.code,
        }));
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isEdit) {
            form.put(route('admin.builder.object-types.update', editing.id), done);
        } else {
            form.post(route('admin.builder.object-types.store'), done);
        }
    };

    const applyFilters = (next) =>
        router.get(
            route('admin.builder.object-types'),
            { search, category: filters.category, ...next },
            {
                preserveState: true,
                replace: true,
            },
        );

    const confirmDelete = () =>
        router.delete(route('admin.builder.object-types.destroy', deleting.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });

    const toggleChild = (id) =>
        form.setData(
            'allowed_child_type_ids',
            form.data.allowed_child_type_ids.includes(id)
                ? form.data.allowed_child_type_ids.filter((v) => v !== id)
                : [...form.data.allowed_child_type_ids, id],
        );

    return (
        <AuthenticatedLayout title="Object Types">
            <Head title="Object Types" />

            <PageHeader
                title="Object types"
                subtitle="The kinds of thing this organisation governs"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Object types' },
                ]}
                actions={<PrimaryButton onClick={openCreate}>New type</PrimaryButton>}
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {flash.error}
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex flex-col sm:flex-row gap-3">
                <TextInput
                    className="flex-1"
                    placeholder="Search by name or code"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && applyFilters({ search: e.target.value })}
                />
                <select
                    className="sm:w-56 border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                    value={filters.category ?? ''}
                    onChange={(e) => applyFilters({ category: e.target.value })}
                >
                    <option value="">Every category</option>
                    {options.categories.map((category) => (
                        <option key={category} value={category}>
                            {CATEGORY_LABELS[category] ?? category}
                        </option>
                    ))}
                </select>
            </div>

            <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table className="data-table w-full">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Inherits from</th>
                            <th className="text-right">Fields</th>
                            <th className="text-right">Records</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {types.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-sm text-gray-500 text-center py-8">
                                    No object types match.
                                </td>
                            </tr>
                        )}
                        {types.map((type) => (
                            <tr key={type.id}>
                                <td>
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="material-symbols-outlined text-lg"
                                            style={{ color: type.color ?? '#1A365D' }}
                                        >
                                            {type.icon ?? 'category'}
                                        </span>
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">{type.name}</p>
                                            <p className="text-xs text-gray-500 font-mono">{type.code}</p>
                                        </div>
                                        {Boolean(type.is_system) && (
                                            <span className="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                                SYSTEM
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="text-sm text-gray-600">
                                    {CATEGORY_LABELS[type.category] ?? type.category}
                                </td>
                                <td className="text-sm text-gray-600">{type.parent_name ?? '—'}</td>
                                <td className="text-sm text-gray-600 text-right">{type.attribute_definitions_count}</td>
                                <td className="text-sm text-gray-600 text-right">{type.record_count}</td>
                                <td className="text-right whitespace-nowrap">
                                    <Link
                                        href={route('admin.builder.attributes', type.id)}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80 mr-3"
                                    >
                                        Fields
                                    </Link>
                                    <button
                                        type="button"
                                        onClick={() => openEdit(type)}
                                        className="text-xs text-[#1A365D] font-medium hover:opacity-80 mr-3"
                                    >
                                        Edit
                                    </button>
                                    {type.can_delete && (
                                        <button
                                            type="button"
                                            onClick={() => setDeleting(type)}
                                            className="text-xs text-red-600 font-medium hover:opacity-80"
                                        >
                                            Delete
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="3xl">
                <form onSubmit={submit} className="p-6 space-y-5">
                    <h2 className="text-lg font-semibold text-[#1A365D]">
                        {isEdit ? `Edit ${editing.name}` : 'New object type'}
                    </h2>

                    {identityLocked && (
                        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                            This is a seeded type. The platform resolves it by code from a dozen places, so its code,
                            category and node flag are locked — the presentation fields below are yours to change.
                        </p>
                    )}

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={form.data.name}
                                onChange={(e) => setName(e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="plural_name" value="Plural name" />
                            <TextInput
                                id="plural_name"
                                className="mt-1 block w-full"
                                value={form.data.plural_name}
                                onChange={(e) => form.setData('plural_name', e.target.value)}
                                placeholder="Derived from the name if blank"
                            />
                            <InputError message={form.errors.plural_name} className="mt-1" />
                        </div>

                        {!identityLocked && (
                            <>
                                <div>
                                    <InputLabel htmlFor="code" value="Code" />
                                    <TextInput
                                        id="code"
                                        className="mt-1 block w-full font-mono text-sm"
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value)}
                                        required
                                    />
                                    <p className="text-xs text-gray-500 mt-1">
                                        What the platform resolves this type by. It never changes on a rename.
                                    </p>
                                    <InputError message={form.errors.code} className="mt-1" />
                                </div>

                                <div>
                                    <InputLabel htmlFor="category" value="Category" />
                                    <select
                                        id="category"
                                        className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={form.data.category}
                                        onChange={(e) => form.setData('category', e.target.value)}
                                    >
                                        {options.categories.map((category) => (
                                            <option key={category} value={category}>
                                                {CATEGORY_LABELS[category] ?? category}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={form.errors.category} className="mt-1" />
                                </div>
                            </>
                        )}

                        <div>
                            <InputLabel htmlFor="parent_type_id" value="Inherits from" />
                            <select
                                id="parent_type_id"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.parent_type_id ?? ''}
                                onChange={(e) => form.setData('parent_type_id', e.target.value || '')}
                            >
                                <option value="">Nothing</option>
                                {options.types
                                    .filter((option) => option.id !== editing?.id)
                                    .map((option) => (
                                        <option key={option.id} value={option.id}>
                                            {option.name}
                                        </option>
                                    ))}
                            </select>
                            <InputError message={form.errors.parent_type_id} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="default_lifecycle_id" value="Default lifecycle" />
                            <select
                                id="default_lifecycle_id"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.default_lifecycle_id ?? ''}
                                onChange={(e) => form.setData('default_lifecycle_id', e.target.value || '')}
                            >
                                <option value="">None</option>
                                {options.lifecycles.map((lifecycle) => (
                                    <option key={lifecycle.id} value={lifecycle.id}>
                                        {lifecycle.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.default_lifecycle_id} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="icon" value="Icon" />
                            <TextInput
                                id="icon"
                                className="mt-1 block w-full font-mono text-sm"
                                value={form.data.icon}
                                onChange={(e) => form.setData('icon', e.target.value)}
                            />
                            <p className="text-xs text-gray-500 mt-1">A Material Symbols name.</p>
                            <InputError message={form.errors.icon} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="color" value="Colour" />
                            <input
                                id="color"
                                type="color"
                                className="mt-1 block w-full h-10 border-gray-300 rounded-md"
                                value={form.data.color || '#1A365D'}
                                onChange={(e) => form.setData('color', e.target.value)}
                            />
                            <InputError message={form.errors.color} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="code_prefix" value="Reference prefix" />
                            <TextInput
                                id="code_prefix"
                                className="mt-1 block w-full font-mono text-sm uppercase"
                                value={form.data.code_prefix}
                                onChange={(e) => form.setData('code_prefix', e.target.value.toUpperCase())}
                                placeholder="e.g. TP"
                            />
                            <InputError message={form.errors.code_prefix} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="sort_order" value="Sort order" />
                            <TextInput
                                id="sort_order"
                                type="number"
                                min="0"
                                className="mt-1 block w-full"
                                value={form.data.sort_order}
                                onChange={(e) => form.setData('sort_order', e.target.value)}
                            />
                            <InputError message={form.errors.sort_order} className="mt-1" />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="description" value="Description" />
                        <textarea
                            id="description"
                            rows={2}
                            className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                        <InputError message={form.errors.description} className="mt-1" />
                    </div>

                    {!identityLocked && (
                        <label className="flex items-center gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                checked={form.data.is_node_type}
                                onChange={(e) => form.setData('is_node_type', e.target.checked)}
                            />
                            <span className="text-sm text-gray-700">
                                This type is a node in the organisation hierarchy
                            </span>
                        </label>
                    )}

                    <div>
                        <p className="text-sm font-medium text-gray-700 mb-2">Types that may sit beneath this one</p>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 max-h-40 overflow-y-auto">
                            {options.types.map((option) => (
                                <label
                                    key={option.id}
                                    className="flex items-center gap-2 p-2 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                        checked={form.data.allowed_child_type_ids.includes(option.id)}
                                        onChange={() => toggleChild(option.id)}
                                    />
                                    <span className="text-xs text-gray-700">{option.name}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={form.errors.allowed_child_type_ids} className="mt-1" />
                    </div>

                    <div className="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing}>Save</PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={deleting !== null} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900">Delete “{deleting?.name}”?</h2>
                    <p className="text-sm text-gray-600">
                        {deleting?.record_count > 0
                            ? `${deleting.record_count} record(s) still use this type. The delete will be refused and tell you what depends on it.`
                            : 'Nothing records against this type, so it is safe to remove.'}
                    </p>
                    <div className="flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setDeleting(null)}>
                            Cancel
                        </SecondaryButton>
                        <button
                            type="button"
                            onClick={confirmDelete}
                            className="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
