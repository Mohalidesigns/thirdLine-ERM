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

const blank = {
    code: '',
    label: '',
    data_type: 'string',
    maps_to_column: '',
    is_required: false,
    is_unique: false,
    is_pii: false,
    default_value: '',
    help_text: '',
    section: 'Details',
    sort_order: 0,
    width: 'half',
    show_on_mobile: true,
    show_in_detail: true,
    enum_options: [],
    formula: '',
    ref_object_type_id: '',
    extra_rules: [],
    visible_to_roles: [],
    required_permission: '',
    visible_when_field: '',
    visible_when_operator: 'equals',
    visible_when_value: '',
    migration_strategy: 'preserve_as_text',
    confirm_lossy_change: false,
};

const linesToList = (text) =>
    text
        .split(/\r\n|\r|\n/)
        .map((line) => line.trim())
        .filter(Boolean);

const csvToList = (text) =>
    text
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

/**
 * The fields on an object type (migration Phase 6.3).
 *
 * THE DATA TYPE OF A FIELD IN USE IS THE DANGEROUS EDIT. Changing 'text' to
 * 'int' turns "approximately ₦4m" into 0 in every record that ever held it,
 * with no undo. The warning is raised the moment the data type changes rather
 * than at save — a user who discovers at save that their choice is refused has
 * already lost the rest of the form's state to a validation bounce — and the
 * answer comes from MetadataGuard through the impact endpoint, so this page
 * never second-guesses what is safe.
 */
export default function Edit({ objectType, attributes, inherited, options }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [impact, setImpact] = useState(null);

    const form = useForm({ ...blank });
    const isEdit = editing !== null && editing.id !== undefined;

    const openCreate = () => {
        const nextOrder = attributes.reduce((max, a) => Math.max(max, a.sort_order ?? 0), 0) + 10;
        form.setData({ ...blank, sort_order: nextOrder });
        form.clearErrors();
        setImpact(null);
        setEditing({});
    };

    const openEdit = (attribute) => {
        form.setData({ ...blank, ...attribute, ref_object_type_id: attribute.ref_object_type_id ?? '' });
        form.clearErrors();
        setImpact(null);
        setEditing(attribute);
    };

    // Ask the server what the change would cost, exactly as the Livewire
    // screen did on every data-type change.
    const changeDataType = (value) => {
        form.setData('data_type', value);

        if (!isEdit || value === editing.data_type) {
            setImpact(null);
            form.setData('confirm_lossy_change', false);

            return;
        }

        fetch(route('admin.builder.attributes.impact', [objectType.id, editing.id]) + `?data_type=${value}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((body) => {
                setImpact(body.needs_migration_path ? body : null);
                form.setData('confirm_lossy_change', false);
            })
            .catch(() => setImpact(null));
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isEdit) {
            form.put(route('admin.builder.attributes.update', [objectType.id, editing.id]), done);
        } else {
            form.post(route('admin.builder.attributes.store', objectType.id), done);
        }
    };

    const confirmDelete = () =>
        router.delete(route('admin.builder.attributes.destroy', [objectType.id, deleting.id]), {
            data: { confirmed: true },
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });

    const toggleRole = (role) =>
        form.setData(
            'visible_to_roles',
            form.data.visible_to_roles.includes(role)
                ? form.data.visible_to_roles.filter((r) => r !== role)
                : [...form.data.visible_to_roles, role],
        );

    const needsOptions = ['enum', 'multi_enum'].includes(form.data.data_type);
    const sections = [...new Set(attributes.map((a) => a.section || 'Details'))];

    return (
        <AuthenticatedLayout title={`${objectType.name} fields`}>
            <Head title={`${objectType.name} fields`} />

            <PageHeader
                title={`${objectType.name} fields`}
                subtitle="What this type records, and how each field behaves on the form"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Object types', href: route('admin.builder.object-types') },
                    { label: objectType.name },
                ]}
                actions={<PrimaryButton onClick={openCreate}>New field</PrimaryButton>}
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

            {sections.map((section) => (
                <div key={section} className="bg-white rounded-xl border border-gray-200 mb-4 overflow-x-auto">
                    <p className="px-4 pt-4 text-xs font-semibold text-gray-500 uppercase tracking-wide">{section}</p>
                    <table className="data-table w-full">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Type</th>
                                <th>Rules</th>
                                <th className="text-right">In use</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {attributes
                                .filter((a) => (a.section || 'Details') === section)
                                .map((attribute) => (
                                    <tr key={attribute.id}>
                                        <td>
                                            <p className="text-sm font-medium text-gray-900">{attribute.label}</p>
                                            <p className="text-xs text-gray-500 font-mono">{attribute.code}</p>
                                        </td>
                                        <td className="text-sm text-gray-600">
                                            {options.dataTypes[attribute.data_type] ?? attribute.data_type}
                                        </td>
                                        <td className="text-xs text-gray-500">
                                            {[
                                                attribute.is_required && 'required',
                                                attribute.is_unique && 'unique',
                                                attribute.is_pii && 'PII',
                                                attribute.maps_to_column && `column ${attribute.maps_to_column}`,
                                            ]
                                                .filter(Boolean)
                                                .join(', ') || '—'}
                                        </td>
                                        <td className="text-sm text-gray-600 text-right">{attribute.usage_count}</td>
                                        <td className="text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(attribute)}
                                                className="text-xs text-[#1A365D] font-medium hover:opacity-80 mr-3"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setDeleting(attribute)}
                                                className="text-xs text-red-600 font-medium hover:opacity-80"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                </div>
            ))}

            {attributes.length === 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500">
                    This type records nothing of its own yet.
                </div>
            )}

            {inherited.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-200 p-4">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                        Inherited from its ancestors
                    </p>
                    <p className="text-xs text-gray-500 mb-3">
                        Edited on the type that defines them. They are listed here so nobody defines a duplicate.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        {inherited.map((attribute) => (
                            <span
                                key={attribute.code}
                                className="px-2 py-1 rounded bg-gray-100 text-xs text-gray-700"
                                title={options.dataTypes[attribute.data_type] ?? attribute.data_type}
                            >
                                {attribute.label}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="4xl">
                <form onSubmit={submit} className="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">
                        {isEdit ? `Edit ${editing.label}` : 'New field'}
                    </h2>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel htmlFor="label" value="Label" />
                            <TextInput
                                id="label"
                                className="mt-1 block w-full"
                                value={form.data.label}
                                onChange={(e) => form.setData('label', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.label} className="mt-1" />
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
                            <InputLabel htmlFor="data_type" value="Data type" />
                            <select
                                id="data_type"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.data_type}
                                onChange={(e) => changeDataType(e.target.value)}
                            >
                                {Object.entries(options.dataTypes).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.data_type} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="section" value="Section" />
                            <TextInput
                                id="section"
                                className="mt-1 block w-full"
                                value={form.data.section}
                                onChange={(e) => form.setData('section', e.target.value)}
                            />
                            <InputError message={form.errors.section} className="mt-1" />
                        </div>
                    </div>

                    {impact && (
                        <div className="rounded-lg border border-red-200 bg-red-50 p-4 space-y-3">
                            <p className="text-sm font-semibold text-red-900">
                                This change can lose what {impact.affected_records} record(s) already hold.
                            </p>
                            {impact.reason && <p className="text-xs text-red-800">{impact.reason}</p>}
                            <div>
                                <InputLabel htmlFor="migration_strategy" value="What should happen to those values?" />
                                <select
                                    id="migration_strategy"
                                    className="mt-1 block w-full border-red-300 focus:border-red-500 focus:ring-red-500 rounded-md"
                                    value={form.data.migration_strategy}
                                    onChange={(e) => form.setData('migration_strategy', e.target.value)}
                                >
                                    {options.migrationStrategies.map((strategy) => (
                                        <option key={strategy} value={strategy}>
                                            {strategy.replace(/_/g, ' ')}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <label className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-red-300 text-red-600 focus:ring-red-500"
                                    checked={form.data.confirm_lossy_change}
                                    onChange={(e) => form.setData('confirm_lossy_change', e.target.checked)}
                                />
                                <span className="text-sm text-red-900">
                                    I understand this cannot be undone, and I have chosen what happens to the values.
                                </span>
                            </label>
                        </div>
                    )}

                    {needsOptions && (
                        <div>
                            <InputLabel htmlFor="enum_options" value="Options" />
                            <textarea
                                id="enum_options"
                                rows={4}
                                className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.enum_options.join('\n')}
                                onChange={(e) => form.setData('enum_options', linesToList(e.target.value))}
                                placeholder="One per line"
                            />
                            <InputError message={form.errors.enum_options} className="mt-1" />
                        </div>
                    )}

                    {form.data.data_type === 'formula' && (
                        <div>
                            <InputLabel htmlFor="formula" value="Formula" />
                            <TextInput
                                id="formula"
                                className="mt-1 block w-full font-mono text-sm"
                                value={form.data.formula}
                                onChange={(e) => form.setData('formula', e.target.value)}
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Computed, never posted — the platform rejects input for a calculated field outright.
                            </p>
                            <InputError message={form.errors.formula} className="mt-1" />
                        </div>
                    )}

                    {form.data.data_type === 'object_ref' && (
                        <div className="sm:w-1/2">
                            <InputLabel htmlFor="ref_object_type_id" value="Links to" />
                            <select
                                id="ref_object_type_id"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.ref_object_type_id ?? ''}
                                onChange={(e) => form.setData('ref_object_type_id', e.target.value || '')}
                            >
                                <option value="">Choose a type</option>
                                {options.types.map((type) => (
                                    <option key={type.id} value={type.id}>
                                        {type.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.ref_object_type_id} className="mt-1" />
                        </div>
                    )}

                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        {[
                            ['is_required', 'Required'],
                            ['is_unique', 'Unique'],
                            ['is_pii', 'Personal data'],
                            ['show_on_mobile', 'Show on mobile'],
                            ['show_in_detail', 'Show on the detail view'],
                        ].map(([field, label]) => (
                            <label key={field} className="flex items-center gap-2 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                    checked={Boolean(form.data[field])}
                                    onChange={(e) => form.setData(field, e.target.checked)}
                                />
                                <span className="text-sm text-gray-700">{label}</span>
                            </label>
                        ))}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <InputLabel htmlFor="width" value="Width" />
                            <select
                                id="width"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.width}
                                onChange={(e) => form.setData('width', e.target.value)}
                            >
                                <option value="half">Half</option>
                                <option value="full">Full</option>
                            </select>
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
                        </div>
                        <div>
                            <InputLabel htmlFor="default_value" value="Default value" />
                            <TextInput
                                id="default_value"
                                className="mt-1 block w-full"
                                value={form.data.default_value ?? ''}
                                onChange={(e) => form.setData('default_value', e.target.value)}
                            />
                        </div>
                    </div>

                    <div>
                        <InputLabel htmlFor="help_text" value="Help text" />
                        <TextInput
                            id="help_text"
                            className="mt-1 block w-full"
                            value={form.data.help_text ?? ''}
                            onChange={(e) => form.setData('help_text', e.target.value)}
                        />
                        <InputError message={form.errors.help_text} className="mt-1" />
                    </div>

                    <details className="rounded-lg border border-gray-200 p-4">
                        <summary className="text-sm font-medium text-gray-700 cursor-pointer">
                            Visibility and extra rules
                        </summary>

                        <div className="mt-4 space-y-4">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <InputLabel htmlFor="visible_when_field" value="Show only when field" />
                                    <TextInput
                                        id="visible_when_field"
                                        className="mt-1 block w-full font-mono text-sm"
                                        value={form.data.visible_when_field ?? ''}
                                        onChange={(e) => form.setData('visible_when_field', e.target.value)}
                                        placeholder="another field's code"
                                    />
                                </div>
                                <div>
                                    <InputLabel htmlFor="visible_when_operator" value="Operator" />
                                    <select
                                        id="visible_when_operator"
                                        className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                        value={form.data.visible_when_operator}
                                        onChange={(e) => form.setData('visible_when_operator', e.target.value)}
                                    >
                                        <option value="equals">equals</option>
                                        <option value="not_equals">does not equal</option>
                                        <option value="in">is one of</option>
                                        <option value="filled">has a value</option>
                                    </select>
                                </div>
                                <div>
                                    <InputLabel htmlFor="visible_when_value" value="Value" />
                                    <TextInput
                                        id="visible_when_value"
                                        className="mt-1 block w-full"
                                        value={form.data.visible_when_value ?? ''}
                                        onChange={(e) => form.setData('visible_when_value', e.target.value)}
                                    />
                                </div>
                            </div>

                            <div>
                                <InputLabel htmlFor="extra_rules" value="Extra validation rules" />
                                <TextInput
                                    id="extra_rules"
                                    className="mt-1 block w-full font-mono text-sm"
                                    value={form.data.extra_rules.join(', ')}
                                    onChange={(e) => form.setData('extra_rules', csvToList(e.target.value))}
                                    placeholder="min:3, max:20"
                                />
                                <p className="text-xs text-gray-500 mt-1">
                                    Laravel rules, comma separated. Merged with the rules the data type already implies.
                                </p>
                                <InputError message={form.errors.extra_rules} className="mt-1" />
                            </div>

                            <div>
                                <InputLabel htmlFor="required_permission" value="Only visible with permission" />
                                <select
                                    id="required_permission"
                                    className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={form.data.required_permission ?? ''}
                                    onChange={(e) => form.setData('required_permission', e.target.value)}
                                >
                                    <option value="">Anyone who can see the record</option>
                                    {options.permissions.map((permission) => (
                                        <option key={permission} value={permission}>
                                            {permission}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.required_permission} className="mt-1" />
                            </div>

                            <div>
                                <p className="text-sm font-medium text-gray-700 mb-2">Only visible to roles</p>
                                <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 max-h-36 overflow-y-auto">
                                    {options.roles.map((role) => (
                                        <label
                                            key={role}
                                            className="flex items-center gap-2 p-2 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                checked={form.data.visible_to_roles.includes(role)}
                                                onChange={() => toggleRole(role)}
                                            />
                                            <span className="text-xs text-gray-700">{role}</span>
                                        </label>
                                    ))}
                                </div>
                                <p className="text-xs text-gray-500 mt-1">
                                    Leave every box clear to show the field to everyone.
                                </p>
                            </div>

                            <div>
                                <InputLabel htmlFor="maps_to_column" value="Maps to an existing column" />
                                <TextInput
                                    id="maps_to_column"
                                    className="mt-1 block w-full font-mono text-sm"
                                    value={form.data.maps_to_column ?? ''}
                                    onChange={(e) => form.setData('maps_to_column', e.target.value)}
                                />
                                <p className="text-xs text-gray-500 mt-1">
                                    A mapped field is displayed from that column and never written through this form.
                                </p>
                                <InputError message={form.errors.maps_to_column} className="mt-1" />
                            </div>
                        </div>
                    </details>

                    <div className="flex justify-end gap-3 pt-2 border-t border-gray-100">
                        <SecondaryButton type="button" onClick={() => setEditing(null)}>
                            Cancel
                        </SecondaryButton>
                        <PrimaryButton disabled={form.processing || (impact && !form.data.confirm_lossy_change)}>
                            Save
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>

            <Modal show={deleting !== null} onClose={() => setDeleting(null)} maxWidth="md">
                <div className="p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900">Delete “{deleting?.label}”?</h2>
                    <p className="text-sm text-gray-600">
                        {deleting?.usage_count > 0
                            ? `${deleting.usage_count} record(s) hold a value in this field. Deleting it discards those values.`
                            : 'Nothing holds a value in this field.'}
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
