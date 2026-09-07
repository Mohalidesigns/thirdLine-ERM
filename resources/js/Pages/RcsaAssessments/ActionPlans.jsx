import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import InputError from '@thirdline/ui/Components/InputError';

/**
 * The workbook's columns U, V and W — what will be done about a risk above
 * appetite, by whom, and by when (§8.4).
 *
 * IT APPEARS BECAUSE THE RISK IS ABOVE APPETITE, not because somebody opened a
 * panel. Rule 6 makes the block mandatory when `appetite_status` says so, and
 * the submission gate refuses while any such line has no complete plan — so
 * this component's job is to make that the obvious next thing to do rather
 * than a surprise at the end of the quarter.
 *
 * MANY PLANS PER RISK, which is defect D5: the workbook has one cell and a
 * risk above appetite routinely needs three actions with three owners.
 */
export default function ActionPlans({ assessmentId, line, owners = [], editable, onChanged }) {
    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);

    const plans = line.action_plans ?? [];
    const required = (line.appetite_status ?? '').startsWith('Above risk appetite');
    const complete = plans.filter((p) => p.control_to_implement && p.owner_id && p.target_date);

    if (!required && plans.length === 0) {
        return null;
    }

    return (
        <div
            className={`rounded-lg border p-4 ${
                required && complete.length === 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'
            }`}
        >
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h4 className="text-sm font-semibold text-gray-800">Risk treatment plan</h4>
                    <p className="mt-0.5 text-sm text-gray-600">
                        {required ? (
                            complete.length === 0 ? (
                                <span className="text-red-700">
                                    This risk is above appetite, so it cannot be submitted without a control, an
                                    owner and a date.
                                </span>
                            ) : (
                                `${complete.length} plan${complete.length === 1 ? '' : 's'} recorded.`
                            )
                        ) : (
                            'This risk is within appetite. A plan is optional.'
                        )}
                    </p>
                </div>

                {editable && !adding && (
                    <button type="button" onClick={() => setAdding(true)} className="btn-secondary text-sm">
                        + Add plan
                    </button>
                )}
            </div>

            {plans.length > 0 && (
                <ul className="mt-3 space-y-2">
                    {plans.map((plan) =>
                        editingId === plan.id ? (
                            <li key={plan.id}>
                                <PlanForm
                                    assessmentId={assessmentId}
                                    line={line}
                                    plan={plan}
                                    owners={owners}
                                    onDone={() => {
                                        setEditingId(null);
                                        onChanged?.();
                                    }}
                                    onCancel={() => setEditingId(null)}
                                />
                            </li>
                        ) : (
                            <li
                                key={plan.id}
                                className="flex items-start justify-between gap-3 rounded-md border border-gray-200 bg-white p-3"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm text-gray-800">{plan.control_to_implement}</p>
                                    <p className="mt-1 text-xs text-gray-500">
                                        {plan.owner ?? (
                                            <span className="text-red-600">No owner</span>
                                        )}{' '}
                                        · by{' '}
                                        {plan.target_date ?? <span className="text-red-600">no date</span>}
                                        {plan.is_overdue && (
                                            <span className="ml-2 font-medium text-amber-700">overdue</span>
                                        )}
                                    </p>
                                </div>

                                {editable && (
                                    <div className="flex shrink-0 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setEditingId(plan.id)}
                                            className="text-xs text-gray-500 hover:text-[var(--color-primary)]"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                if (!window.confirm('Remove this action plan?')) return;

                                                router.delete(
                                                    route('rcsa.assessments.plans.destroy', [
                                                        assessmentId,
                                                        line.id,
                                                        plan.id,
                                                    ]),
                                                    { preserveScroll: true, onSuccess: () => onChanged?.() },
                                                );
                                            }}
                                            className="text-xs text-red-600 hover:underline"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                )}
                            </li>
                        ),
                    )}
                </ul>
            )}

            {adding && (
                <div className="mt-3">
                    <PlanForm
                        assessmentId={assessmentId}
                        line={line}
                        owners={owners}
                        onDone={() => {
                            setAdding(false);
                            onChanged?.();
                        }}
                        onCancel={() => setAdding(false)}
                    />
                </div>
            )}
        </div>
    );
}

function PlanForm({ assessmentId, line, plan = null, owners, onDone, onCancel }) {
    const form = useForm({
        control_to_implement: plan?.control_to_implement ?? '',
        owner_id: plan?.owner_id ?? '',
        target_date: plan?.target_date ?? '',
    });

    const submit = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (plan) {
            form.put(route('rcsa.assessments.plans.update', [assessmentId, line.id, plan.id]), options);
        } else {
            form.post(route('rcsa.assessments.plans.store', [assessmentId, line.id]), options);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border border-gray-200 bg-white p-3">
            <div>
                <label className="mb-1 block text-sm font-medium text-gray-700">Control to be implemented</label>
                <textarea
                    rows={2}
                    autoFocus
                    className="filter-input w-full"
                    placeholder="What will be put in place to bring this risk within appetite?"
                    value={form.data.control_to_implement}
                    onChange={(e) => form.setData('control_to_implement', e.target.value)}
                />
                <InputError message={form.errors.control_to_implement} className="mt-1" />
            </div>

            <div className="grid gap-3 md:grid-cols-2">
                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">
                        Person to act / risk owner
                    </label>
                    <select
                        className="filter-select w-full"
                        value={form.data.owner_id}
                        onChange={(e) => form.setData('owner_id', e.target.value)}
                    >
                        <option value="">Choose…</option>
                        {owners.map((owner) => (
                            <option key={owner.id} value={owner.id}>
                                {owner.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.owner_id} className="mt-1" />
                </div>

                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Implementation date</label>
                    <input
                        type="date"
                        className="filter-input w-full"
                        value={form.data.target_date}
                        onChange={(e) => form.setData('target_date', e.target.value)}
                    />
                    <InputError message={form.errors.target_date} className="mt-1" />
                </div>
            </div>

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onCancel} className="btn-secondary text-sm">
                    Cancel
                </button>
                <button type="submit" disabled={form.processing} className="btn-primary text-sm">
                    {plan ? 'Save plan' : 'Add plan'}
                </button>
            </div>
        </form>
    );
}
