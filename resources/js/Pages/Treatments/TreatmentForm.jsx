import DynamicForm from '@/Components/DynamicForm';
import InputError from '@/Components/InputError';

/**
 * The create and edit form for a treatment plan (migration Phase 3.5).
 *
 * WP-05 TASK 2 — plan details, the four-strategy response and the expected
 * outcome are all configured fields on the TreatmentPlan object type, so they
 * render from the schema rather than being written out here. Expected residual
 * likelihood and impact come through on the organisation's own scoring scale,
 * not a hardcoded 1–5, so a tenant on a 4×4 matrix cannot record a 5 the
 * matrix has no room for.
 *
 * Milestones stay hand-written: they are a repeating group posted as
 * milestones[n][...], which is a different shape from a field and belongs to
 * this form. The Blade pages built the repeater twice, in two copies of the
 * same jQuery-ish DOM script; it is one component here.
 */
const EMPTY_MILESTONE = { title: '', due_date: '', responsible: '' };

function Section({ index, title, children }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
            <div className="flex items-center gap-2 mb-6">
                <div className="w-8 h-8 rounded-full bg-[#1A365D] text-white flex items-center justify-center text-sm font-bold">
                    {index}
                </div>
                <h2 className="text-lg font-semibold text-[#1A365D]">{title}</h2>
            </div>
            {children}
        </div>
    );
}

function MilestoneRow({ milestone, index, onChange, onRemove, errors }) {
    const field = (key, label, type = 'text', placeholder = '') => (
        <div>
            <label className="block text-xs font-medium text-gray-600 mb-1">{label}</label>
            <input
                type={type}
                value={milestone[key] ?? ''}
                placeholder={placeholder}
                onChange={(e) => onChange(index, key, e.target.value)}
                className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
            />
            <InputError message={errors[`milestones.${index}.${key}`]} className="mt-1" />
        </div>
    );

    return (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-4 p-4 bg-gray-50 rounded-lg">
            <div className="lg:col-span-5">{field('title', 'Milestone Title', 'text', 'e.g. Requirements gathering complete')}</div>
            <div className="lg:col-span-3">{field('due_date', 'Due Date', 'date')}</div>
            <div className="lg:col-span-3">{field('responsible', 'Responsible', 'text', 'Name or role')}</div>
            <div className="lg:col-span-1 flex items-end">
                <button
                    type="button"
                    onClick={() => onRemove(index)}
                    title="Remove"
                    className="p-2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-lg"
                >
                    <span className="material-symbols-outlined text-lg">delete</span>
                </button>
            </div>
        </div>
    );
}

export default function TreatmentForm({ schema, data, setData, errors = {}, detailsSection = 1 }) {
    const milestones = data.milestones ?? [];

    const setMilestones = (next) => setData('milestones', next);

    const changeMilestone = (index, key, value) =>
        setMilestones(milestones.map((row, i) => (i === index ? { ...row, [key]: value } : row)));

    /**
     * Removing the last row empties it rather than leaving none, matching the
     * edit page's behaviour: the form always offers somewhere to type.
     */
    const removeMilestone = (index) =>
        setMilestones(milestones.length <= 1 ? [{ ...EMPTY_MILESTONE }] : milestones.filter((_, i) => i !== index));

    return (
        <>
            <Section index={detailsSection} title="Plan Details">
                <DynamicForm
                    schema={schema}
                    values={data}
                    errors={errors}
                    onChange={(code, value) => setData(code, value)}
                />
            </Section>

            <Section index={detailsSection + 1} title="Milestones">
                <div>
                    {milestones.map((milestone, index) => (
                        <MilestoneRow
                            key={index}
                            milestone={milestone}
                            index={index}
                            onChange={changeMilestone}
                            onRemove={removeMilestone}
                            errors={errors}
                        />
                    ))}
                </div>

                <button
                    type="button"
                    onClick={() => setMilestones([...milestones, { ...EMPTY_MILESTONE }])}
                    className="flex items-center gap-2 px-4 py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-600 hover:border-[#1A365D] hover:text-[#1A365D] transition-colors"
                >
                    <span className="material-symbols-outlined text-lg">add</span> Add Milestone
                </button>

                <InputError message={errors.milestones} className="mt-1" />
            </Section>
        </>
    );
}

export { EMPTY_MILESTONE };
