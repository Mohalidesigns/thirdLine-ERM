import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Modal from '@/Components/Modal';

const blankState = (overrides = {}) => ({
    code: '',
    name: '',
    color: '#3b82f6',
    is_initial: false,
    is_terminal: false,
    allowed_transitions: [],
    required_permission: '',
    required_workflow_id: '',
    ...overrides,
});

const blank = {
    object_type_id: '',
    code: '',
    name: '',
    states: [],
};

/**
 * State machines (migration Phase 6.3).
 *
 * The machine is drawn as a matrix of "from" rows against "to" columns, which
 * is the one layout that makes the mistake people actually make visible at a
 * glance: a state with no way out.
 *
 * COHERENCE IS THE SERVER'S. Exactly one initial state, at least one terminal
 * state, no transition to a state that does not exist, and no state
 * unreachable from the initial one — all checked by MetadataGuard, so a
 * configuration bundle import is held to the same rules as this screen. The
 * errors come back keyed on `states` and render above the matrix.
 *
 * A SEEDED LIFECYCLE IS NEVER EDITED IN PLACE. Its states are what the domain
 * tables' existing strings conform to, so saving over one clones it into this
 * organisation's own namespace instead. The dialog says so before you save.
 */
export default function Edit({ lifecycles, filters, options }) {
    const { flash } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const form = useForm({ ...blank });
    const isEdit = editing !== null && editing.id !== undefined;
    const willClone = isEdit && Boolean(editing.is_system);

    const openCreate = () => {
        form.setData({
            ...blank,
            object_type_id: filters.object_type_id ?? '',
            // A usable starting machine rather than a blank page: two states
            // and the one transition between them already satisfies every
            // coherence rule, so the first save cannot fail on a technicality.
            states: [
                blankState({ code: 'draft', name: 'Draft', is_initial: true, allowed_transitions: ['closed'] }),
                blankState({ code: 'closed', name: 'Closed', is_terminal: true, color: '#6b7280' }),
            ],
        });
        form.clearErrors();
        setEditing({});
    };

    const openEdit = (lifecycle) => {
        form.setData({
            object_type_id: lifecycle.object_type_id ?? '',
            code: lifecycle.code ?? '',
            name: lifecycle.name ?? '',
            states: (lifecycle.states ?? []).map((state) => blankState(state)),
        });
        form.clearErrors();
        setEditing(lifecycle);
    };

    const setState = (index, key, value) =>
        form.setData(
            'states',
            form.data.states.map((state, i) => (i === index ? { ...state, [key]: value } : state)),
        );

    // Only one state can be the initial one; setting it clears the others.
    const setInitial = (index) =>
        form.setData(
            'states',
            form.data.states.map((state, i) => ({ ...state, is_initial: i === index })),
        );

    const toggleTransition = (fromIndex, toCode) =>
        form.setData(
            'states',
            form.data.states.map((state, i) => {
                if (i !== fromIndex) return state;

                const current = state.allowed_transitions ?? [];

                return {
                    ...state,
                    allowed_transitions: current.includes(toCode)
                        ? current.filter((code) => code !== toCode)
                        : [...current, toCode],
                };
            }),
        );

    const removeState = (index) => {
        const removed = form.data.states[index]?.code;

        // Drop transitions that pointed at it, rather than leaving a dangling
        // target the coherence check would reject with a message about a state
        // the user has already deleted.
        form.setData(
            'states',
            form.data.states
                .filter((_, i) => i !== index)
                .map((state) => ({
                    ...state,
                    allowed_transitions: (state.allowed_transitions ?? []).filter((code) => code !== removed),
                })),
        );
    };

    const submit = (event) => {
        event.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => setEditing(null) };

        if (isEdit) {
            form.put(route('admin.builder.lifecycles.update', editing.id), done);
        } else {
            form.post(route('admin.builder.lifecycles.store'), done);
        }
    };

    const confirmDelete = () =>
        router.delete(route('admin.builder.lifecycles.destroy', deleting.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });

    const filterByType = (value) =>
        router.get(route('admin.builder.lifecycles'), value ? { object_type_id: value } : {}, {
            preserveState: true,
            replace: true,
        });

    const namedStates = form.data.states.filter((state) => (state.code ?? '').trim() !== '');

    return (
        <AuthenticatedLayout title="Lifecycles">
            <Head title="Lifecycles" />

            <PageHeader
                title="Lifecycles"
                subtitle="The states a record moves through, and what each transition demands"
                breadcrumbs={[
                    { label: 'Configuration Builder', href: route('admin.builder') },
                    { label: 'Lifecycles' },
                ]}
                actions={<PrimaryButton onClick={openCreate}>New lifecycle</PrimaryButton>}
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

            <div className="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                <select
                    className="sm:w-72 border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                    value={filters.object_type_id ?? ''}
                    onChange={(e) => filterByType(e.target.value)}
                >
                    <option value="">Every object type</option>
                    {options.types.map((type) => (
                        <option key={type.id} value={type.id}>
                            {type.name}
                        </option>
                    ))}
                </select>
            </div>

            <div className="space-y-4">
                {lifecycles.length === 0 && (
                    <div className="bg-white rounded-xl border border-gray-200 p-8 text-center text-sm text-gray-500">
                        No lifecycles yet.
                    </div>
                )}

                {lifecycles.map((lifecycle) => (
                    <div key={lifecycle.id} className="bg-white rounded-xl border border-gray-200 p-5">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className="text-sm font-semibold text-gray-900">{lifecycle.name}</h3>
                                    {Boolean(lifecycle.is_system) && (
                                        <span className="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-600">
                                            SYSTEM
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-gray-500 font-mono">{lifecycle.code}</p>
                                <p className="text-xs text-gray-500 mt-0.5">{lifecycle.object_type_name}</p>
                            </div>
                            <div className="flex gap-3 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => openEdit(lifecycle)}
                                    className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                                >
                                    Edit
                                </button>
                                {lifecycle.can_delete && (
                                    <button
                                        type="button"
                                        onClick={() => setDeleting(lifecycle)}
                                        className="text-xs text-red-600 font-medium hover:opacity-80"
                                    >
                                        Delete
                                    </button>
                                )}
                            </div>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            {lifecycle.states.map((state) => (
                                <span
                                    key={state.code}
                                    className="px-2 py-1 rounded-full text-xs font-medium text-white"
                                    style={{ backgroundColor: state.color || '#94a3b8' }}
                                >
                                    {state.name}
                                    {state.is_initial ? ' ·start' : ''}
                                    {state.is_terminal ? ' ·end' : ''}
                                </span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            <Modal show={editing !== null} onClose={() => setEditing(null)} maxWidth="4xl">
                <form onSubmit={submit} className="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
                    <h2 className="text-lg font-semibold text-[#1A365D]">
                        {isEdit ? `Edit ${editing.name}` : 'New lifecycle'}
                    </h2>

                    {willClone && (
                        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                            This is a seeded lifecycle. Its states are what the existing records already conform to, so
                            saving copies it into this organisation's own namespace rather than changing it in place.
                        </p>
                    )}

                    {form.errors.states && (
                        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                            {form.errors.states}
                        </div>
                    )}

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
                            <InputLabel htmlFor="object_type_id" value="Applies to" />
                            <select
                                id="object_type_id"
                                className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={form.data.object_type_id ?? ''}
                                onChange={(e) => form.setData('object_type_id', e.target.value)}
                                required
                            >
                                <option value="">Choose a type</option>
                                {options.types.map((type) => (
                                    <option key={type.id} value={type.id}>
                                        {type.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.object_type_id} className="mt-1" />
                        </div>
                    </div>

                    <div className="space-y-3">
                        <p className="text-sm font-semibold text-gray-700">States</p>

                        {form.data.states.map((state, index) => (
                            <div key={index} className="rounded-lg border border-gray-200 p-4 space-y-3">
                                <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
                                    <div>
                                        <InputLabel value="Code" />
                                        <TextInput
                                            className="mt-1 block w-full font-mono text-sm"
                                            value={state.code}
                                            onChange={(e) => setState(index, 'code', e.target.value)}
                                            required
                                        />
                                        <InputError message={form.errors[`states.${index}.code`]} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Name" />
                                        <TextInput
                                            className="mt-1 block w-full"
                                            value={state.name}
                                            onChange={(e) => setState(index, 'name', e.target.value)}
                                            required
                                        />
                                        <InputError message={form.errors[`states.${index}.name`]} className="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Colour" />
                                        <input
                                            type="color"
                                            className="mt-1 block w-full h-10 border-gray-300 rounded-md"
                                            value={state.color || '#3b82f6'}
                                            onChange={(e) => setState(index, 'color', e.target.value)}
                                        />
                                        <InputError message={form.errors[`states.${index}.color`]} className="mt-1" />
                                    </div>
                                    <div className="flex items-end gap-4 pb-2">
                                        <label className="flex items-center gap-1.5 cursor-pointer">
                                            <input
                                                type="radio"
                                                name="initial-state"
                                                className="border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                checked={Boolean(state.is_initial)}
                                                onChange={() => setInitial(index)}
                                            />
                                            <span className="text-xs text-gray-700">Start</span>
                                        </label>
                                        <label className="flex items-center gap-1.5 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                checked={Boolean(state.is_terminal)}
                                                onChange={(e) => setState(index, 'is_terminal', e.target.checked)}
                                            />
                                            <span className="text-xs text-gray-700">End</span>
                                        </label>
                                        <button
                                            type="button"
                                            onClick={() => removeState(index)}
                                            className="text-xs text-red-600 font-medium hover:opacity-80 ml-auto"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Leaving this state needs" />
                                        <select
                                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                                            value={state.required_permission ?? ''}
                                            onChange={(e) => setState(index, 'required_permission', e.target.value)}
                                        >
                                            <option value="">No particular permission</option>
                                            {options.permissions.map((permission) => (
                                                <option key={permission} value={permission}>
                                                    {permission}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors[`states.${index}.required_permission`]}
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <InputLabel value="…and must run through" />
                                        <select
                                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                                            value={state.required_workflow_id ?? ''}
                                            onChange={(e) => setState(index, 'required_workflow_id', e.target.value)}
                                        >
                                            <option value="">No workflow</option>
                                            {options.workflows.map((workflow) => (
                                                <option key={workflow.id} value={workflow.id}>
                                                    {workflow.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors[`states.${index}.required_workflow_id`]}
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </div>
                        ))}

                        <button
                            type="button"
                            onClick={() => form.setData('states', [...form.data.states, blankState()])}
                            className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                        >
                            + Add a state
                        </button>
                    </div>

                    {namedStates.length > 1 && (
                        <div>
                            <p className="text-sm font-semibold text-gray-700 mb-1">Transitions</p>
                            <p className="text-xs text-gray-500 mb-3">
                                A row is a state you are in; a ticked column is somewhere you may go. A row with nothing
                                ticked is a state with no way out.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-xs border border-gray-200 rounded-lg">
                                    <thead>
                                        <tr className="bg-gray-50">
                                            <th className="p-2 text-left text-gray-500 font-medium">From \ To</th>
                                            {namedStates.map((state) => (
                                                <th key={state.code} className="p-2 text-gray-600 font-medium">
                                                    {state.name || state.code}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {form.data.states.map((from, fromIndex) =>
                                            (from.code ?? '').trim() === '' ? null : (
                                                <tr key={fromIndex} className="border-t border-gray-200">
                                                    <td className="p-2 text-gray-700 font-medium whitespace-nowrap">
                                                        {from.name || from.code}
                                                    </td>
                                                    {namedStates.map((to) => (
                                                        <td key={to.code} className="p-2 text-center">
                                                            {to.code === from.code ? (
                                                                <span className="text-gray-300">·</span>
                                                            ) : (
                                                                <input
                                                                    type="checkbox"
                                                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                                    checked={(from.allowed_transitions ?? []).includes(
                                                                        to.code,
                                                                    )}
                                                                    onChange={() =>
                                                                        toggleTransition(fromIndex, to.code)
                                                                    }
                                                                />
                                                            )}
                                                        </td>
                                                    ))}
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

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
                        If an object type still defaults to this lifecycle, the delete is refused and says which.
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
