import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import Modal from '@thirdline/ui/Components/Modal';

const CARDINALITY_LABELS = {
    one_to_one: 'One to one',
    one_to_many: 'One to many',
    many_to_many: 'Many to many',
};

const blank = {
    code: '',
    name: '',
    inverse_code: '',
    from_type_ids: [],
    to_type_ids: [],
    cardinality: 'many_to_many',
    has_weight: false,
    attribute_schema: [],
};

function TypePicker({ label, selected, options, onToggle, error }) {
    return (
        <div>
            <p className="text-sm font-medium text-gray-700 mb-1">{label}</p>
            <p className="text-xs text-gray-500 mb-2">Leave every box clear to allow any type.</p>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-40 overflow-y-auto">
                {options.map((option) => (
                    <label
                        key={option.id}
                        className="flex items-center gap-2 p-2 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                    >
                        <input
                            type="checkbox"
                            className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={selected.includes(option.id)}
                            onChange={() => onToggle(option.id)}
                        />
                        <span className="text-xs text-gray-700">{option.name}</span>
                    </label>
                ))}
            </div>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * Typed edges (migration Phase 6.3).
 *
 * `weight` is the field that matters most and is easiest to overlook: it is
 * what makes graph-derived roll-up possible at all, because it is where "this
 * control covers 40% of this risk" is recorded.
 *
 * The edge's own attribute schema is edited as rows here rather than as the
 * "code|Label|type" text the Livewire form asked an SME to type. The parse is
 * unchanged and still the server's; doing it in the browser was the only thing
 * that made the text shape necessary.
 */
export default function Index({ relationshipTypes, options }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

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
            inverse_code: type.inverse_code ?? '',
            from_type_ids: type.from_type_ids ?? [],
            to_type_ids: type.to_type_ids ?? [],
            cardinality: type.cardinality ?? 'many_to_many',
            has_weight: Boolean(type.has_weight),
            attribute_schema: type.attribute_schema ?? [],
        });
        form.clearErrors();
        setEditing(type);
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isEdit) {
            form.put(route('admin.builder.relationship-types.update', editing.id), done);
        } else {
            form.post(route('admin.builder.relationship-types.store'), done);
        }
    };

    const confirmDelete = () =>
        router.delete(route('admin.builder.relationship-types.destroy', deleting.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });

    const toggle = (field, id) =>
        form.setData(
            field,
            form.data[field].includes(id) ? form.data[field].filter((v) => v !== id) : [...form.data[field], id],
        );

    const setSchemaRow = (index, key, value) =>
        form.setData(
            'attribute_schema',
            form.data.attribute_schema.map((row, i) => (i === index ? { ...row, [key]: value } : row)),
        );

    const typeName = (id) => options.types.find((t) => t.id === id)?.name ?? id;

    return (
        <AuthenticatedLayout title="Relationship Types">
            <Head title="Relationship Types" />

            <PageHeader
                title="Relationship types"
                subtitle="How things connect, and with what weight"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Relationship types' },
                ]}
                actions={<PrimaryButton onClick={openCreate}>New relationship type</PrimaryButton>}
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

            <div className="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table className="data-table w-full">
                    <thead>
                        <tr>
                            <th>Relationship</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Cardinality</th>
                            <th className="text-right">In use</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {relationshipTypes.length === 0 && (
                            <tr>
                                <td colSpan={6} className="text-sm text-gray-500 text-center py-8">
                                    Nothing defined yet.
                                </td>
                            </tr>
                        )}
                        {relationshipTypes.map((type) => (
                            <tr key={type.id}>
                                <td>
                                    <div className="flex items-center gap-2">
                                        <div>
                                            <p className="text-sm font-medium text-gray-900">{type.name}</p>
                                            <p className="text-xs text-gray-500 font-mono">
                                                {type.code}
                                                {type.inverse_code ? ` ⇄ ${type.inverse_code}` : ''}
                                            </p>
                                        </div>
                                        {Boolean(type.is_system) && (
                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                                SYSTEM
                                            </span>
                                        )}
                                        {Boolean(type.has_weight) && (
                                            <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-blue-50 text-blue-700">
                                                WEIGHTED
                                            </span>
                                        )}
                                    </div>
                                </td>
                                <td className="text-xs text-gray-600">
                                    {type.from_type_ids.length === 0
                                        ? 'Any'
                                        : type.from_type_ids.map(typeName).join(', ')}
                                </td>
                                <td className="text-xs text-gray-600">
                                    {type.to_type_ids.length === 0 ? 'Any' : type.to_type_ids.map(typeName).join(', ')}
                                </td>
                                <td className="text-sm text-gray-600">
                                    {CARDINALITY_LABELS[type.cardinality] ?? type.cardinality}
                                </td>
                                <td className="text-sm text-gray-600 text-right">{type.instance_count}</td>
                                <td className="text-right whitespace-nowrap">
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
                <form onSubmit={submit} className="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">
                        {isEdit ? `Edit ${editing.name}` : 'New relationship type'}
                    </h2>

                    {identityLocked && (
                        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                            This is a seeded relationship type. The platform resolves it by code, so the code is locked.
                        </p>
                    )}

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
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

                        {!identityLocked && (
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
                        )}

                        <div>
                            <InputLabel htmlFor="inverse_code" value="Inverse code" />
                            <TextInput
                                id="inverse_code"
                                className="mt-1 block w-full font-mono text-sm"
                                value={form.data.inverse_code ?? ''}
                                onChange={(e) => form.setData('inverse_code', e.target.value)}
                                placeholder="e.g. covered_by"
                            />
                            <InputError message={form.errors.inverse_code} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="cardinality" value="Cardinality" />
                            <select
                                id="cardinality"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.cardinality}
                                onChange={(e) => form.setData('cardinality', e.target.value)}
                            >
                                {options.cardinalities.map((value) => (
                                    <option key={value} value={value}>
                                        {CARDINALITY_LABELS[value] ?? value}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.cardinality} className="mt-1" />
                        </div>
                    </div>

                    <label className="flex items-start gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            className="mt-0.5 rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            checked={form.data.has_weight}
                            onChange={(e) => form.setData('has_weight', e.target.checked)}
                        />
                        <span className="text-sm text-gray-700">
                            Edges of this type carry a weight
                            <span className="block text-xs text-gray-500">
                                What makes roll-up across the graph possible — it is where "this control covers 40% of
                                this risk" is recorded.
                            </span>
                        </span>
                    </label>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <TypePicker
                            label="From these types"
                            selected={form.data.from_type_ids}
                            options={options.types}
                            onToggle={(id) => toggle('from_type_ids', id)}
                            error={form.errors.from_type_ids}
                        />
                        <TypePicker
                            label="To these types"
                            selected={form.data.to_type_ids}
                            options={options.types}
                            onToggle={(id) => toggle('to_type_ids', id)}
                            error={form.errors.to_type_ids}
                        />
                    </div>

                    <div>
                        <p className="text-sm font-medium text-gray-700 mb-2">What an edge of this type records</p>
                        <div className="space-y-2">
                            {form.data.attribute_schema.map((row, index) => (
                                <div key={index} className="flex flex-col sm:flex-row gap-2">
                                    <input
                                        type="text"
                                        placeholder="code"
                                        className="flex-1 text-sm font-mono border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={row.code ?? ''}
                                        onChange={(e) => setSchemaRow(index, 'code', e.target.value)}
                                    />
                                    <input
                                        type="text"
                                        placeholder="Label"
                                        className="flex-1 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={row.label ?? ''}
                                        onChange={(e) => setSchemaRow(index, 'label', e.target.value)}
                                    />
                                    <select
                                        className="sm:w-40 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={row.type ?? 'string'}
                                        onChange={(e) => setSchemaRow(index, 'type', e.target.value)}
                                    >
                                        {options.dataTypes.map((type) => (
                                            <option key={type} value={type}>
                                                {type}
                                            </option>
                                        ))}
                                    </select>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData(
                                                'attribute_schema',
                                                form.data.attribute_schema.filter((_, i) => i !== index),
                                            )
                                        }
                                        className="text-xs text-red-600 font-medium hover:opacity-80 px-2"
                                    >
                                        Remove
                                    </button>
                                </div>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() =>
                                form.setData('attribute_schema', [
                                    ...form.data.attribute_schema,
                                    { code: '', label: '', type: 'string' },
                                ])
                            }
                            className="mt-2 text-xs text-[#1A365D] font-medium hover:opacity-80"
                        >
                            + Add a field
                        </button>
                        {Object.entries(form.errors)
                            .filter(([key]) => key.startsWith('attribute_schema.'))
                            .map(([key, message]) => (
                                <InputError key={key} message={message} className="mt-1" />
                            ))}
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
                    <h2 className="text-lg font-semibold text-gray-900">Archive “{deleting?.name}”?</h2>
                    <p className="text-sm text-gray-600">
                        {deleting?.instance_count > 0
                            ? `${deleting.instance_count} relationship(s) use this type. They are archived rather than deleted — retained for audit, but no longer traversed.`
                            : 'Nothing uses this type.'}
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
                            Archive
                        </button>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
