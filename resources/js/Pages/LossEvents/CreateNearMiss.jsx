import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import PageHeader from '@/Components/PageHeader';
import { titleCase } from './format';

const INPUT = 'w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]';

function Field({ label, required = false, error, help, children, className = '' }) {
    return (
        <div className={className}>
            <label className="block text-xs font-medium text-gray-600 mb-1">
                {label} {required && <span className="text-red-500">*</span>}
            </label>
            {children}
            {help && <p className="text-xs text-gray-500 mt-1">{help}</p>}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/** Migration Phase 4.3: risk/loss-events/create-near-miss.blade.php. */
export default function CreateNearMiss({
    risks = [],
    businessUnits = [],
    users = [],
    controls = [],
    severities = [],
}) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        date_occurred: new Date().toISOString().slice(0, 10),
        business_unit_id: '',
        risk_register_id: '',
        severity: '',
        potential_loss_amount: '',
        control_gap_identified: false,
        control_gap_description: '',
        linked_control_id: '',
        reported_by: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.loss-events.store-near-miss'));
    };

    const text = (name) => (e) => setData(name, e.target.value);

    return (
        <AuthenticatedLayout title="Report Near Miss">
            <Head title="Report Near Miss" />

            <PageHeader
                title="Report a Near Miss"
                subtitle="An event that could have caused a loss but did not"
                breadcrumbs={[
                    { label: 'Loss Events', href: route('risk.loss-events.index') },
                    { label: 'Near Misses', href: route('risk.loss-events.near-misses') },
                    { label: 'Report' },
                ]}
            />

            <form onSubmit={submit} className="max-w-4xl">
                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-base font-bold text-[#1A365D] mb-6">What Nearly Happened</h2>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Field label="Title" required error={errors.title} className="lg:col-span-2">
                            <input type="text" value={data.title} onChange={text('title')} className={INPUT} />
                        </Field>

                        <Field label="Description" required error={errors.description} className="lg:col-span-2">
                            <textarea rows={4} value={data.description} onChange={text('description')} className={INPUT} />
                        </Field>

                        <Field label="Date Occurred" required error={errors.date_occurred}>
                            <input type="date" value={data.date_occurred} onChange={text('date_occurred')} className={INPUT} />
                        </Field>

                        <Field label="Severity" required error={errors.severity} help="How bad it would have been.">
                            <select value={data.severity} onChange={text('severity')} className={INPUT}>
                                <option value="">Select</option>
                                {severities.map((severity) => (
                                    <option key={severity} value={severity}>{titleCase(severity)}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Business Unit" required error={errors.business_unit_id}>
                            <select value={data.business_unit_id} onChange={text('business_unit_id')} className={INPUT}>
                                <option value="">Select a unit</option>
                                {businessUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
                            </select>
                        </Field>

                        <Field label="Reported By" required error={errors.reported_by}>
                            <select value={data.reported_by} onChange={text('reported_by')} className={INPUT}>
                                <option value="">Select</option>
                                {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                            </select>
                        </Field>

                        <Field label="Potential Loss Avoided" error={errors.potential_loss_amount} help="What it would have cost, if known.">
                            <input type="number" step="0.01" min="0" value={data.potential_loss_amount} onChange={text('potential_loss_amount')} className={INPUT} />
                        </Field>

                        <Field label="Linked Risk" error={errors.risk_register_id}>
                            <select value={data.risk_register_id} onChange={text('risk_register_id')} className={INPUT}>
                                <option value="">Not linked</option>
                                {risks.map((risk) => <option key={risk.id} value={risk.id}>{risk.code} — {risk.title}</option>)}
                            </select>
                        </Field>
                    </div>
                </div>

                <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
                    <h2 className="text-base font-bold text-[#1A365D] mb-6">Controls</h2>

                    <label className="flex items-start gap-3 mb-4">
                        <input
                            type="checkbox"
                            checked={Boolean(data.control_gap_identified)}
                            onChange={(e) => setData('control_gap_identified', e.target.checked)}
                            className="rounded border-gray-300 text-[#1A365D] mt-0.5"
                        />
                        <span className="text-sm text-gray-700">
                            A control gap was identified
                            <span className="block text-xs text-gray-500 mt-0.5">
                                A near miss with a gap behind it is the cheapest warning a bank gets.
                            </span>
                        </span>
                    </label>

                    {data.control_gap_identified && (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            <Field label="What Was Missing" error={errors.control_gap_description} className="lg:col-span-2">
                                <textarea rows={3} value={data.control_gap_description} onChange={text('control_gap_description')} className={INPUT} />
                            </Field>

                            <Field label="Control Concerned" error={errors.linked_control_id} className="lg:col-span-2">
                                <select value={data.linked_control_id} onChange={text('linked_control_id')} className={INPUT}>
                                    <option value="">Not linked</option>
                                    {controls.map((control) => (
                                        <option key={control.id} value={control.id}>{control.code} — {control.name}</option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                    )}
                </div>

                <div className="flex items-center justify-between">
                    <Link href={route('risk.loss-events.near-misses')} className="btn-secondary text-sm">Cancel</Link>
                    <button type="submit" disabled={processing} className="btn-primary inline-flex items-center gap-2 text-sm disabled:opacity-50">
                        <span className="material-symbols-outlined text-lg">save</span> Report Near Miss
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
