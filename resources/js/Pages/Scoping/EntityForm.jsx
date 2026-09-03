import InputError from '@/Components/InputError';
import RichTextEditor from '@/Components/RichTextEditor';
import DynamicForm, { fieldsOf, formDataFor, initialValues } from '@/Components/DynamicForm';

function Section({ number, title, children, hint }) {
    return (
        <div className="card mb-6">
            <div className="card-header flex items-center gap-2">
                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-[#1A365D] text-xs font-bold text-white">{number}</div>
                <div>
                    <h3 className="text-sm font-semibold text-[#1A365D]">{title}</h3>
                    {hint && <p className="text-xs text-gray-400">{hint}</p>}
                </div>
            </div>
            <div className="card-body">{children}</div>
        </div>
    );
}

/**
 * The shared field set of Scoping/Create and Scoping/Edit (migration Phase
 * 3.1: risk/scoping/{create,edit}.blade.php). Column-backed fields are laid
 * out by hand; the tenant's configured fields for the CHOSEN entity type
 * render through DynamicForm from `schemas[entity_type_id]`.
 */
export default function EntityForm({
    data, setData, errors,
    entityTypes = [], parentEntities = [], users = [], frameworks = [], appetiteLevels = [], appetiteCategories = [],
    schemas = {}, entityCode = null, editing = false,
}) {
    const schema = schemas?.[data.entity_type_id] ?? null;
    const hasConfigured = schema && fieldsOf(schema).length > 0;

    const toggleFramework = (framework, checked) => {
        const current = Array.isArray(data.regulatory_frameworks) ? data.regulatory_frameworks : [];
        setData('regulatory_frameworks', checked ? [...current, framework] : current.filter((f) => f !== framework));
    };

    const statuses = editing
        ? [['active', 'Active'], ['inactive', 'Inactive'], ['archived', 'Archived']]
        : [['active', 'Active'], ['inactive', 'Inactive']];

    return (
        <div>
            <Section number={1} title="Basic Information">
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">Entity Code</label>
                        <input type="text" value={entityCode ?? 'ENT-AUTO-GENERATED'} disabled className="form-input w-full bg-gray-50 text-gray-500" />
                        {!entityCode && <p className="mt-1 text-xs text-gray-500">Auto-generated on save</p>}
                    </div>
                    <div>
                        <label htmlFor="entity_type_id" className="mb-1 block text-sm font-medium text-gray-700">
                            Entity Type <span className="text-red-500">*</span>
                        </label>
                        <select
                            id="entity_type_id"
                            value={data.entity_type_id ?? ''}
                            onChange={(e) => setData('entity_type_id', e.target.value === '' ? '' : Number(e.target.value))}
                            className="form-input w-full"
                            required
                        >
                            <option value="">Select Type</option>
                            {entityTypes.map((type) => (
                                <option key={type.id} value={type.id}>{type.name} (L{type.level})</option>
                            ))}
                        </select>
                        <InputError message={errors.entity_type_id} className="mt-1" />
                    </div>
                    <div className="lg:col-span-2">
                        <label htmlFor="name" className="mb-1 block text-sm font-medium text-gray-700">
                            Entity Name <span className="text-red-500">*</span>
                        </label>
                        <input
                            id="name"
                            type="text"
                            value={data.name ?? ''}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g., Retail Banking Division"
                            className="form-input w-full"
                            required
                        />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div className="lg:col-span-2">
                        <label htmlFor="parent_id" className="mb-1 block text-sm font-medium text-gray-700">Parent Entity</label>
                        <select
                            id="parent_id"
                            value={data.parent_id ?? ''}
                            onChange={(e) => setData('parent_id', e.target.value === '' ? null : Number(e.target.value))}
                            className="form-input w-full"
                        >
                            <option value="">None (Root Entity)</option>
                            {parentEntities.map((parent) => (
                                <option key={parent.id} value={parent.id}>
                                    {parent.code} — {parent.name}{parent.type ? ` (${parent.type})` : ''}
                                </option>
                            ))}
                        </select>
                        <p className="mt-1 text-xs text-gray-500">Leave empty for Group-level (root) entities</p>
                        <InputError message={errors.parent_id} className="mt-1" />
                    </div>
                    <div className="lg:col-span-2">
                        <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                        <RichTextEditor
                            value={data.description || ''}
                            onChange={(value) => setData('description', value)}
                            placeholder="Entity purpose, mandate, and key responsibilities..."
                            minHeight={100}
                        />
                        <InputError message={errors.description} className="mt-1" />
                    </div>
                </div>
            </Section>

            <Section number={2} title="Ownership & Delegation">
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <div>
                        <label htmlFor="owner_id" className="mb-1 block text-sm font-medium text-gray-700">Entity Owner</label>
                        <select
                            id="owner_id"
                            value={data.owner_id ?? ''}
                            onChange={(e) => setData('owner_id', e.target.value === '' ? null : Number(e.target.value))}
                            className="form-input w-full"
                        >
                            <option value="">Select Owner</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>{user.name} ({user.email})</option>
                            ))}
                        </select>
                        <InputError message={errors.owner_id} className="mt-1" />
                    </div>
                    <div>
                        <label htmlFor="delegate_owner_id" className="mb-1 block text-sm font-medium text-gray-700">Delegate Owner</label>
                        <select
                            id="delegate_owner_id"
                            value={data.delegate_owner_id ?? ''}
                            onChange={(e) => setData('delegate_owner_id', e.target.value === '' ? null : Number(e.target.value))}
                            className="form-input w-full"
                        >
                            <option value="">None</option>
                            {users.map((user) => (
                                <option key={user.id} value={user.id}>{user.name} ({user.email})</option>
                            ))}
                        </select>
                        <InputError message={errors.delegate_owner_id} className="mt-1" />
                    </div>
                    <div className="lg:col-span-2">
                        <label className="mb-2 block text-sm font-medium text-gray-700">Status</label>
                        <div className="flex flex-wrap gap-6">
                            {statuses.map(([value, label]) => (
                                <label key={value} className="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="status"
                                        value={value}
                                        checked={data.status === value}
                                        onChange={() => setData('status', value)}
                                        className="rounded-full border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                    />
                                    <span className="text-sm text-gray-700">{label}</span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.status} className="mt-1" />
                    </div>
                </div>
            </Section>

            <Section number={3} title="Regulatory Scope" hint="Select applicable regulatory frameworks for this entity">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    {frameworks.map((framework) => {
                        const checked = (data.regulatory_frameworks ?? []).includes(framework);

                        return (
                            <label key={framework} className="flex cursor-pointer items-center gap-3 rounded-lg bg-gray-50 p-3 transition-colors hover:bg-blue-50">
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    onChange={(e) => toggleFramework(framework, e.target.checked)}
                                    className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                                />
                                <span className="text-sm font-medium text-gray-700">{framework}</span>
                            </label>
                        );
                    })}
                </div>
                <InputError message={errors.regulatory_frameworks} className="mt-1" />
            </Section>

            <Section number={4} title="Risk Appetite">
                <div className="mb-5">
                    <label htmlFor="risk_appetite_level" className="mb-1 block text-sm font-medium text-gray-700">Overall Risk Appetite</label>
                    <select
                        id="risk_appetite_level"
                        value={data.risk_appetite_level ?? ''}
                        onChange={(e) => setData('risk_appetite_level', e.target.value || null)}
                        className="form-input w-full max-w-md"
                    >
                        <option value="">Select Appetite Level</option>
                        {appetiteLevels.map((level) => (
                            <option key={level.value} value={level.value}>{level.label}</option>
                        ))}
                    </select>
                    <InputError message={errors.risk_appetite_level} className="mt-1" />
                </div>

                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <h4 className="mb-3 text-sm font-semibold text-[#1A365D]">Category-Level Risk Appetite</h4>
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        {appetiteCategories.map((category) => (
                            <div key={category.value}>
                                <label className="mb-1 block text-xs font-semibold text-gray-600">{category.label}</label>
                                <select
                                    value={data.category_appetites?.[category.value] ?? ''}
                                    onChange={(e) => setData('category_appetites', { ...(data.category_appetites ?? {}), [category.value]: e.target.value || null })}
                                    className="form-input w-full bg-white"
                                >
                                    <option value="">Not Set</option>
                                    {appetiteLevels.map((level) => (
                                        <option key={level.value} value={level.value}>{level.label}</option>
                                    ))}
                                </select>
                                <InputError message={errors[`category_appetites.${category.value}`]} className="mt-1" />
                            </div>
                        ))}
                    </div>
                </div>
            </Section>

            {hasConfigured && (
                <Section number={5} title="Additional Fields" hint={`Configured for ${schema.objectType?.name ?? 'this entity type'}`}>
                    <DynamicForm
                        schema={schema}
                        values={data.configured_attributes ?? {}}
                        onChange={(code, value) => setData('configured_attributes', { ...(data.configured_attributes ?? {}), [code]: value })}
                        errors={errors}
                    />
                </Section>
            )}
        </div>
    );
}

/** The post body: the columns as they are, the configured fields as the server reads them. */
export function toPayload(data, schemas) {
    const { configured_attributes, ...rest } = data;
    const schema = schemas?.[data.entity_type_id] ?? null;

    return { ...rest, ...(schema ? formDataFor(schema, configured_attributes ?? {}) : {}) };
}

/** Seed `configured_attributes` for a type's schema, keeping anything already typed. */
export function seedConfigured(schema, current = {}) {
    return { ...initialValues(schema ?? { sections: [] }), ...current };
}
