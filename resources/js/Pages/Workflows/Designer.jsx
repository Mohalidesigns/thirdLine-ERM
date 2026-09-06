import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputLabel from '@thirdline/ui/Components/InputLabel';
import TextInput from '@thirdline/ui/Components/TextInput';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';
import DesignerCanvas from './DesignerCanvas';

const TRIGGER_LABELS = {
    manual: 'When somebody starts it',
    on_create: 'When the record is created',
    on_transition: 'When the record changes state',
    on_event: 'When a named event fires',
    on_schedule: 'On a schedule',
};

const slugify = (value) =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

function Field({ label, hint, children, error }) {
    return (
        <div>
            <InputLabel>
                <span className="text-xs font-medium text-gray-600">{label}</span>
            </InputLabel>
            {children}
            {hint && <p className="text-xs text-gray-500 mt-1">{hint}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * The workflow designer (migration Phase 6.5).
 *
 * VALIDATION IS ON PUBLISH, NOT ON SAVE. A half-drawn process is a normal thing
 * to have saved. What must never happen is a half-drawn process being the one
 * new instances start on, so publishing runs the full check and reports every
 * error at once rather than one per attempt. `liveErrors` shows that same list
 * while you work, so publishing is never the first time you hear about a
 * problem.
 *
 * Node positions are stored on the node and posted with the graph, which is why
 * the canvas needs no layout algorithm: a process looks the same to the next
 * person who opens it.
 */
export default function Designer({ definition, skeleton, options, liveErrors }) {
    const { flash, errors: pageErrors } = usePage().props;

    const [selectedNode, setSelectedNode] = useState(null);
    const [selectedEdge, setSelectedEdge] = useState(null);
    const [edgeFrom, setEdgeFrom] = useState('');
    const [edgeTo, setEdgeTo] = useState('');
    const [edgeNotice, setEdgeNotice] = useState(null);

    const form = useForm({
        code: definition?.code ?? '',
        name: definition?.name ?? '',
        description: definition?.description ?? '',
        entity_type: definition?.entity_type ?? '',
        object_type_id: definition?.object_type_id ?? '',
        trigger: definition?.trigger ?? 'manual',
        definition: definition?.graph ?? skeleton,
        escalation_rules: definition?.escalation_rules ?? [],
    });

    const nodes = form.data.definition.nodes ?? [];
    const edges = form.data.definition.edges ?? [];

    const setGraph = (next) => form.setData('definition', { ...form.data.definition, ...next });

    const node = nodes.find((n) => n.code === selectedNode) ?? null;
    const edge = selectedEdge !== null ? (edges[selectedEdge] ?? null) : null;
    const nodeType = options.nodeTypes.find((t) => t.value === node?.type);

    /* ---- nodes ---- */

    const uniqueCode = (base) => {
        const taken = new Set(nodes.map((n) => n.code));
        if (!taken.has(base)) return base;

        let suffix = 2;
        while (taken.has(`${base}_${suffix}`)) suffix += 1;

        return `${base}_${suffix}`;
    };

    const addNode = (type) => {
        const meta = options.nodeTypes.find((t) => t.value === type);
        const code = uniqueCode(type);

        const next = { code, type, name: meta?.label ?? type, x: 320, y: 320 };

        if (meta?.waits_for_human) {
            Object.assign(next, {
                assignee_rule: 'role',
                assignee_config: { roles: [] },
                sla_hours: 72,
                on_timeout: 'escalate',
                allow_delegate: true,
                allow_return: true,
            });
        }

        if (type === 'end') next.outcome = 'approved';

        setGraph({ nodes: [...nodes, next] });
        setSelectedNode(code);
        setSelectedEdge(null);
    };

    const setNode = (key, value) =>
        setGraph({ nodes: nodes.map((n) => (n.code === selectedNode ? { ...n, [key]: value } : n)) });

    const moveNode = (code, x, y) => setGraph({ nodes: nodes.map((n) => (n.code === code ? { ...n, x, y } : n)) });

    // Renaming without rewiring is the mistake this handler exists to prevent:
    // it leaves edges pointing at a step that no longer exists, and the failure
    // surfaces as a workflow that silently stops.
    const renameNode = (raw) => {
        const next = slugify(raw);

        if (next === '' || next === selectedNode) return;

        if (nodes.some((n) => n.code === next)) {
            setEdgeNotice(`There is already a step called [${next}].`);

            return;
        }

        setGraph({
            nodes: nodes.map((n) => (n.code === selectedNode ? { ...n, code: next } : n)),
            edges: edges.map((e) => ({
                ...e,
                from: e.from === selectedNode ? next : e.from,
                to: e.to === selectedNode ? next : e.to,
            })),
        });

        form.setData(
            'escalation_rules',
            form.data.escalation_rules.map((rule) => (rule.node === selectedNode ? { ...rule, node: next } : rule)),
        );

        setSelectedNode(next);
        setEdgeNotice(null);
    };

    const deleteNode = () => {
        setGraph({
            nodes: nodes.filter((n) => n.code !== selectedNode),
            edges: edges.filter((e) => e.from !== selectedNode && e.to !== selectedNode),
        });
        setSelectedNode(null);
    };

    /* ---- edges ---- */

    const addEdge = () => {
        if (edgeFrom === '' || edgeTo === '' || edgeFrom === edgeTo) {
            setEdgeNotice('Choose two different steps to connect.');

            return;
        }

        if (edges.some((e) => e.from === edgeFrom && e.to === edgeTo)) {
            setEdgeNotice('Those two steps are already connected.');

            return;
        }

        setGraph({ edges: [...edges, { from: edgeFrom, to: edgeTo, when: null, label: null }] });
        setSelectedEdge(edges.length);
        setSelectedNode(null);
        setEdgeFrom('');
        setEdgeTo('');
        setEdgeNotice(null);
    };

    const setEdge = (key, value) =>
        setGraph({ edges: edges.map((e, i) => (i === selectedEdge ? { ...e, [key]: value } : e)) });

    const deleteEdge = () => {
        setGraph({ edges: edges.filter((_, i) => i !== selectedEdge) });
        setSelectedEdge(null);
    };

    // Order is meaningful, not cosmetic: an exclusive gateway takes the FIRST
    // satisfied edge, so the default branch belongs last and a condition that
    // should win belongs first.
    const moveEdge = (direction) => {
        const target = selectedEdge + direction;
        if (target < 0 || target >= edges.length) return;

        const next = [...edges];
        [next[selectedEdge], next[target]] = [next[target], next[selectedEdge]];

        setGraph({ edges: next });
        setSelectedEdge(target);
    };

    /* ---- persistence ---- */

    const save = (event) => {
        event?.preventDefault();

        if (definition?.id) {
            form.put(route('risk.workflows.save-design', definition.id), { preserveScroll: true });
        } else {
            form.post(route('risk.workflows.create-design'), { preserveScroll: true });
        }
    };

    const publish = () => {
        if (!definition?.id) return;

        router.post(route('risk.workflows.publish-definition', definition.id), {}, { preserveScroll: true });
    };

    const errorEntries = Object.entries(form.errors);

    return (
        <AuthenticatedLayout title="Workflow designer">
            <Head title="Workflow designer" />

            <PageHeader
                title="Workflow designer"
                subtitle="Draw the process: who decides what, in which order, by when, and what happens when a deadline passes"
                breadcrumbs={[
                    { label: 'Workflows', href: route('risk.workflows.dashboard') },
                    { label: 'Definitions', href: route('risk.workflows.definitions') },
                    { label: definition ? `${definition.name} v${definition.version}` : 'New' },
                ]}
                actions={
                    <>
                        <SecondaryButton type="button" onClick={save} disabled={form.processing}>
                            Save draft
                        </SecondaryButton>
                        {definition?.id && definition.can_publish && (
                            <PrimaryButton type="button" onClick={publish}>
                                Publish
                            </PrimaryButton>
                        )}
                    </>
                }
            />

            {flash?.success && (
                <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {flash.success}
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 whitespace-pre-line">
                    {flash.error}
                </div>
            )}

            {errorEntries.length > 0 && (
                <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 space-y-1">
                    <p className="text-sm font-semibold text-red-900">This design could not be saved.</p>
                    {errorEntries.map(([key, message]) => (
                        <p key={key} className="text-xs text-red-800">
                            <span className="font-mono">{key}</span> — {message}
                        </p>
                    ))}
                </div>
            )}

            {liveErrors?.length > 0 && (
                <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <p className="text-sm font-semibold text-amber-900">
                        Not ready to publish. Saving a draft is fine; these stand between it and going live:
                    </p>
                    <ul className="mt-1 text-xs text-amber-900 list-disc list-inside space-y-0.5">
                        {liveErrors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                </div>
            )}

            <form onSubmit={save} className="space-y-4">
                <section className="bg-white rounded-xl border border-gray-200 p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Field label="Name" error={form.errors.name}>
                        <TextInput
                            className="mt-1 block w-full"
                            value={form.data.name}
                            onChange={(e) => {
                                form.setData('name', e.target.value);
                                if (!definition && form.data.code === '') form.setData('code', slugify(e.target.value));
                            }}
                            required
                        />
                    </Field>

                    <Field label="Code" error={form.errors.code} hint="Lower case, digits and underscores.">
                        <TextInput
                            className="mt-1 block w-full font-mono text-sm"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value)}
                            required
                        />
                    </Field>

                    <Field label="Applies to" error={form.errors.entity_type}>
                        <select
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                            value={form.data.entity_type}
                            onChange={(e) => form.setData('entity_type', e.target.value)}
                            required
                        >
                            <option value="">Choose a subject</option>
                            {options.subjectTypes.map((type) => (
                                <option key={type} value={type}>
                                    {type}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Object type" error={form.errors.object_type_id} hint="Optional — narrows it further.">
                        <select
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                            value={form.data.object_type_id ?? ''}
                            onChange={(e) => form.setData('object_type_id', e.target.value || '')}
                        >
                            <option value="">Any</option>
                            {options.objectTypes.map((type) => (
                                <option key={type.id} value={type.id}>
                                    {type.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Starts" error={form.errors.trigger}>
                        <select
                            className="mt-1 block w-full border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm text-sm"
                            value={form.data.trigger}
                            onChange={(e) => form.setData('trigger', e.target.value)}
                        >
                            {options.triggers.map((trigger) => (
                                <option key={trigger} value={trigger}>
                                    {TRIGGER_LABELS[trigger] ?? trigger.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label="Description" error={form.errors.description}>
                        <TextInput
                            className="mt-1 block w-full"
                            value={form.data.description ?? ''}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>
                </section>

                <div className="flex flex-wrap gap-2">
                    {options.nodeTypes.map((type) => (
                        <button
                            key={type.value}
                            type="button"
                            onClick={() => addNode(type.value)}
                            className="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-300 bg-white hover:border-[#1A365D] hover:text-[#1A365D]"
                        >
                            + {type.label}
                        </button>
                    ))}
                </div>

                <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
                    <div className="xl:col-span-2">
                        <DesignerCanvas
                            nodes={nodes}
                            edges={edges}
                            selectedNode={selectedNode}
                            selectedEdge={selectedEdge}
                            onSelectNode={(code) => {
                                setSelectedNode(code);
                                if (code !== null) setSelectedEdge(null);
                            }}
                            onSelectEdge={(index) => {
                                setSelectedEdge(index);
                                if (index !== null) setSelectedNode(null);
                            }}
                            onMoveNode={moveNode}
                        />

                        <div className="mt-3 bg-white rounded-xl border border-gray-200 p-4">
                            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                                Connect two steps
                            </p>
                            <div className="flex flex-col sm:flex-row gap-2">
                                <select
                                    className="flex-1 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={edgeFrom}
                                    onChange={(e) => setEdgeFrom(e.target.value)}
                                >
                                    <option value="">From…</option>
                                    {nodes.map((n) => (
                                        <option key={n.code} value={n.code}>
                                            {n.name}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="flex-1 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                    value={edgeTo}
                                    onChange={(e) => setEdgeTo(e.target.value)}
                                >
                                    <option value="">To…</option>
                                    {nodes.map((n) => (
                                        <option key={n.code} value={n.code}>
                                            {n.name}
                                        </option>
                                    ))}
                                </select>
                                <SecondaryButton type="button" onClick={addEdge}>
                                    Connect
                                </SecondaryButton>
                            </div>
                            {edgeNotice && <p className="text-xs text-red-600 mt-2">{edgeNotice}</p>}
                        </div>
                    </div>

                    <aside className="bg-white rounded-xl border border-gray-200 p-5 space-y-4 self-start">
                        {!node && !edge && (
                            <p className="text-sm text-gray-500">
                                Pick a step or a connection on the canvas to edit it. Drag a step to move it — the
                                position is saved with the process.
                            </p>
                        )}

                        {node && (
                            <>
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-[#1A365D]">{node.name}</h3>
                                    <button
                                        type="button"
                                        onClick={deleteNode}
                                        className="text-xs text-red-600 font-medium hover:opacity-80"
                                    >
                                        Delete step
                                    </button>
                                </div>

                                <Field label="Name">
                                    <TextInput
                                        className="mt-1 block w-full text-sm"
                                        value={node.name ?? ''}
                                        onChange={(e) => setNode('name', e.target.value)}
                                    />
                                </Field>

                                <Field label="Code" hint="Renaming rewires every connection that points at it.">
                                    <TextInput
                                        className="mt-1 block w-full font-mono text-sm"
                                        defaultValue={node.code}
                                        key={node.code}
                                        onBlur={(e) => renameNode(e.target.value)}
                                    />
                                </Field>

                                {nodeType?.waits_for_human && (
                                    <>
                                        <Field label="Who decides">
                                            <select
                                                className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                                value={node.assignee_rule ?? 'role'}
                                                onChange={(e) => setNode('assignee_rule', e.target.value)}
                                            >
                                                <option value="role">Anyone with a role</option>
                                                <option value="user">A named person</option>
                                                <option value="owner">The subject's owner</option>
                                                <option value="delegate">The subject's delegate, then its owner</option>
                                                <option value="manager">The subject's manager</option>
                                                <option value="group">Anyone in a group</option>
                                                {/* A process may carry a rule this
                                                    picker does not build config for —
                                                    relationship_traversal, expression.
                                                    Showing it keeps the select from
                                                    silently rewriting it on open. */}
                                                {!['role', 'user', 'owner', 'delegate', 'manager', 'group'].includes(
                                                    node.assignee_rule ?? 'role',
                                                ) && <option value={node.assignee_rule}>{node.assignee_rule}</option>}
                                            </select>
                                        </Field>

                                        {(node.assignee_rule ?? 'role') === 'role' && (
                                            <div>
                                                <p className="text-xs font-medium text-gray-600 mb-2">Roles</p>
                                                <div className="grid grid-cols-1 gap-1 max-h-40 overflow-y-auto">
                                                    {options.roles.map((role) => (
                                                        <label
                                                            key={role}
                                                            className="flex items-center gap-2 p-1.5 rounded bg-gray-50 hover:bg-blue-50 cursor-pointer"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                                checked={(node.assignee_config?.roles ?? []).includes(
                                                                    role,
                                                                )}
                                                                onChange={() => {
                                                                    const current = node.assignee_config?.roles ?? [];
                                                                    setNode('assignee_config', {
                                                                        ...(node.assignee_config ?? {}),
                                                                        roles: current.includes(role)
                                                                            ? current.filter((r) => r !== role)
                                                                            : [...current, role],
                                                                    });
                                                                }}
                                                            />
                                                            <span className="text-xs text-gray-700">{role}</span>
                                                        </label>
                                                    ))}
                                                </div>
                                            </div>
                                        )}

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="Due within (hours)">
                                                <TextInput
                                                    type="number"
                                                    min="1"
                                                    className="mt-1 block w-full text-sm"
                                                    value={node.sla_hours ?? ''}
                                                    onChange={(e) =>
                                                        setNode(
                                                            'sla_hours',
                                                            e.target.value ? Number(e.target.value) : null,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field label="If it passes">
                                                <select
                                                    className="mt-1 block w-full text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                                    value={node.on_timeout ?? 'escalate'}
                                                    onChange={(e) => setNode('on_timeout', e.target.value)}
                                                >
                                                    <option value="escalate">Escalate</option>
                                                    <option value="notify">Remind whoever it is with</option>
                                                    <option value="auto_approve">Approve automatically</option>
                                                    <option value="auto_reject">Reject automatically</option>
                                                </select>
                                            </Field>
                                        </div>

                                        <div className="space-y-1">
                                            {[
                                                ['allow_delegate', 'May be delegated'],
                                                ['allow_return', 'May be returned for more information'],
                                            ].map(([field, label]) => (
                                                <label key={field} className="flex items-center gap-2 cursor-pointer">
                                                    <input
                                                        type="checkbox"
                                                        className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                                        checked={Boolean(node[field])}
                                                        onChange={(e) => setNode(field, e.target.checked)}
                                                    />
                                                    <span className="text-xs text-gray-700">{label}</span>
                                                </label>
                                            ))}
                                        </div>
                                    </>
                                )}

                                {node.type === 'end' && (
                                    <Field label="Outcome">
                                        <TextInput
                                            className="mt-1 block w-full text-sm"
                                            value={node.outcome ?? ''}
                                            onChange={(e) => setNode('outcome', e.target.value)}
                                        />
                                    </Field>
                                )}
                            </>
                        )}

                        {edge && (
                            <>
                                <div className="flex items-start justify-between gap-2">
                                    <h3 className="text-sm font-semibold text-[#1A365D]">
                                        {edge.from} → {edge.to}
                                    </h3>
                                    <button
                                        type="button"
                                        onClick={deleteEdge}
                                        className="text-xs text-red-600 font-medium hover:opacity-80"
                                    >
                                        Delete
                                    </button>
                                </div>

                                <Field label="Label">
                                    <TextInput
                                        className="mt-1 block w-full text-sm"
                                        value={edge.label ?? ''}
                                        onChange={(e) => setEdge('label', e.target.value || null)}
                                    />
                                </Field>

                                <Field label="Only when" hint="Left blank, this branch is always available.">
                                    <TextInput
                                        className="mt-1 block w-full font-mono text-xs"
                                        value={edge.when ?? ''}
                                        onChange={(e) => setEdge('when', e.target.value || null)}
                                        placeholder="outcome == 'reject'"
                                    />
                                </Field>

                                <div>
                                    <p className="text-xs text-gray-500">
                                        Order matters: a gateway takes the first branch whose condition holds, so the
                                        default belongs last.
                                    </p>
                                    <div className="flex gap-2 mt-2">
                                        <SecondaryButton type="button" onClick={() => moveEdge(-1)}>
                                            Earlier
                                        </SecondaryButton>
                                        <SecondaryButton type="button" onClick={() => moveEdge(1)}>
                                            Later
                                        </SecondaryButton>
                                    </div>
                                </div>
                            </>
                        )}
                    </aside>
                </div>

                <section className="bg-white rounded-xl border border-gray-200 p-5 space-y-3">
                    <div>
                        <h3 className="text-sm font-semibold text-[#1A365D]">Escalation rules</h3>
                        <p className="text-xs text-gray-500">
                            What happens when a step sits unattended. A rule naming a step that is not on the canvas is
                            refused — a deadline that passes in silence is the one thing an escalation rule exists to
                            prevent.
                        </p>
                    </div>

                    {form.data.escalation_rules.map((rule, index) => (
                        <div key={index} className="flex flex-col sm:flex-row gap-2 items-start">
                            <select
                                className="flex-1 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={rule.node ?? ''}
                                onChange={(e) =>
                                    form.setData(
                                        'escalation_rules',
                                        form.data.escalation_rules.map((r, i) =>
                                            i === index ? { ...r, node: e.target.value } : r,
                                        ),
                                    )
                                }
                            >
                                <option value="">Which step…</option>
                                {nodes.map((n) => (
                                    <option key={n.code} value={n.code}>
                                        {n.name}
                                    </option>
                                ))}
                            </select>
                            <TextInput
                                type="number"
                                min="1"
                                className="sm:w-32 text-sm"
                                value={rule.after_hours ?? 24}
                                onChange={(e) =>
                                    form.setData(
                                        'escalation_rules',
                                        form.data.escalation_rules.map((r, i) =>
                                            i === index ? { ...r, after_hours: Number(e.target.value) } : r,
                                        ),
                                    )
                                }
                            />
                            <select
                                className="sm:w-48 text-sm border-gray-300 focus:border-[#1A365D] focus:ring-[#1A365D] rounded-md shadow-sm"
                                value={rule.action ?? 'escalate'}
                                onChange={(e) =>
                                    form.setData(
                                        'escalation_rules',
                                        form.data.escalation_rules.map((r, i) =>
                                            i === index ? { ...r, action: e.target.value } : r,
                                        ),
                                    )
                                }
                            >
                                <option value="escalate">Escalate</option>
                                <option value="notify">Notify</option>
                                <option value="auto_approve">Approve automatically</option>
                                <option value="auto_reject">Reject automatically</option>
                            </select>
                            <button
                                type="button"
                                onClick={() =>
                                    form.setData(
                                        'escalation_rules',
                                        form.data.escalation_rules.filter((_, i) => i !== index),
                                    )
                                }
                                className="text-xs text-red-600 font-medium hover:opacity-80 px-2 py-2.5"
                            >
                                Remove
                            </button>
                        </div>
                    ))}

                    <button
                        type="button"
                        onClick={() =>
                            form.setData('escalation_rules', [
                                ...form.data.escalation_rules,
                                {
                                    node: null,
                                    after_hours: 24,
                                    action: 'escalate',
                                    escalate_to: { rule: 'role', roles: [] },
                                },
                            ])
                        }
                        className="text-xs text-[#1A365D] font-medium hover:opacity-80"
                    >
                        + Add a rule
                    </button>
                </section>

                <div className="flex items-center justify-end gap-3 pb-8">
                    <Link href={route('risk.workflows.definitions')}>
                        <SecondaryButton type="button">Back to definitions</SecondaryButton>
                    </Link>
                    <PrimaryButton disabled={form.processing}>Save draft</PrimaryButton>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
