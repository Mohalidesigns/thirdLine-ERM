import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm } from '@inertiajs/react';
import { InputError, PageHeader } from '@thirdline/ui';

const nowLocal = () => {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
};

export default function Create({
    types = [],
    baselEventTypes = [],
    severities = [],
    entities = [],
    processes = [],
    users = [],
    controls = [],
    risks = [],
}) {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        description: '',
        incident_type: 'operational',
        basel_event_type: '',
        severity: 'Medium',
        occurred_at: nowLocal(),
        detected_at: nowLocal(),
        detection_method: '',
        entity_id: '',
        process_id: '',
        owner_id: '',
        near_miss: false,
        currency: 'NGN',
        gross_loss_minor: '',
        recovery_minor: '',
        control_failure_ids: [],
        risk_ids: [],
    });

    const toggle = (field, id) => {
        const current = data[field];
        setData(field, current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('risk.incidents.store'));
    };

    return (
        <AppLayout title="Report an Incident">
            <Head title="Report an Incident" />

            <PageHeader
                title="Report an Incident"
                subtitle="Report first, classify later — the notification clock starts from when we became aware"
                breadcrumbs={[
                    { label: 'Incident Register', href: route('risk.incidents.index') },
                    { label: 'New' },
                ]}
            />

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6 pb-12">
                <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                    <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-4">
                        What Happened
                    </div>
                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">
                                Title <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="Brief title summarizing the incident"
                            />
                            <InputError message={errors.title} className="mt-1" />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">
                                Description <span className="text-red-500">*</span>
                            </label>
                            <textarea
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                rows={4}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Detail what occurred, containment status, known impacts, systems or people affected..."
                            />
                            <InputError message={errors.description} className="mt-1" />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-sm font-medium text-gray-700">Type</label>
                                <select
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.incident_type}
                                    onChange={(e) => setData('incident_type', e.target.value)}
                                >
                                    {types.map((type) => (
                                        <option key={type} value={type}>
                                            {type.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-gray-500">
                                    The incident type triggers statutory reporting obligations (e.g. NDPC 72h data breach, CBN 72h cyber, CBN 48h fraud).
                                </p>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700">Severity</label>
                                <select
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.severity}
                                    onChange={(e) => setData('severity', e.target.value)}
                                >
                                    {severities.map((severity) => (
                                        <option key={severity} value={severity}>
                                            {severity}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700">Basel Event Type</label>
                                <select
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.basel_event_type}
                                    onChange={(e) => setData('basel_event_type', e.target.value)}
                                >
                                    <option value="">— Select Basel Event Type —</option>
                                    {baselEventTypes.map((type) => (
                                        <option key={type} value={type}>
                                            {type}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700">Detection Method</label>
                                <input
                                    type="text"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.detection_method}
                                    onChange={(e) => setData('detection_method', e.target.value)}
                                    placeholder="Monitoring alert, customer report, internal audit, reconciliation..."
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700">Occurred At</label>
                                <input
                                    type="datetime-local"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                />
                                <InputError message={errors.occurred_at} className="mt-1" />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700">Detected At</label>
                                <input
                                    type="datetime-local"
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    value={data.detected_at}
                                    onChange={(e) => setData('detected_at', e.target.value)}
                                />
                                <InputError message={errors.detected_at} className="mt-1" />
                            </div>
                        </div>

                        <label className="flex items-start gap-2 text-sm pt-2">
                            <input
                                type="checkbox"
                                className="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                checked={data.near_miss}
                                onChange={(e) => setData('near_miss', e.target.checked)}
                            />
                            <span>
                                <strong className="text-gray-900">This was a near miss</strong>
                                <span className="block text-xs text-gray-500">
                                    Near misses carry no financial loss but act as key leading indicators for operational control breakdowns.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>

                <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                    <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-4">
                        Organizational Context
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Business Unit / Entity</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.entity_id}
                                onChange={(e) => setData('entity_id', e.target.value)}
                            >
                                <option value="">— Select Business Unit —</option>
                                {entities.map((entity) => (
                                    <option key={entity.id} value={entity.id}>
                                        {entity.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Process</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.process_id}
                                onChange={(e) => setData('process_id', e.target.value)}
                            >
                                <option value="">— Select Process —</option>
                                {processes.map((process) => (
                                    <option key={process.id} value={process.id}>
                                        {process.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">Owner</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.owner_id}
                                onChange={(e) => setData('owner_id', e.target.value)}
                            >
                                <option value="">— Assign Owner —</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                    <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-4">
                        Financial Loss & Recovery
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">Currency</label>
                            <select
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.currency}
                                onChange={(e) => setData('currency', e.target.value)}
                            >
                                {['NGN', 'USD', 'GBP', 'EUR', 'GHS', 'KES', 'ZAR'].map((curr) => (
                                    <option key={curr} value={curr}>
                                        {curr}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">
                                Gross Loss (minor units, e.g. kobo/cents)
                            </label>
                            <input
                                type="number"
                                min="0"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.gross_loss_minor}
                                onChange={(e) => setData('gross_loss_minor', e.target.value)}
                                placeholder="e.g. 10000000 for 100,000 NGN"
                            />
                            <InputError message={errors.gross_loss_minor} className="mt-1" />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700">
                                Recovery (minor units, e.g. kobo/cents)
                            </label>
                            <input
                                type="number"
                                min="0"
                                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                value={data.recovery_minor}
                                onChange={(e) => setData('recovery_minor', e.target.value)}
                                placeholder="e.g. 2500000 for 25,000 NGN"
                            />
                            <InputError message={errors.recovery_minor} className="mt-1" />
                        </div>
                    </div>
                    <p className="mt-2 text-xs text-gray-400">
                        Net loss is calculated automatically as gross loss minus recovery.
                    </p>
                </div>

                <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                    <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-2">
                        Controls that Failed
                    </div>
                    <p className="mb-3 text-xs text-gray-500">
                        Linking a control flags it for re-testing and highlights control breakdown trends.
                    </p>
                    <div className="max-h-56 space-y-2 overflow-y-auto divide-y divide-gray-100">
                        {controls.map((control) => (
                            <label key={control.id} className="flex items-center gap-2 pt-2 text-sm text-gray-700 hover:text-gray-900 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    checked={data.control_failure_ids.includes(control.id)}
                                    onChange={() => toggle('control_failure_ids', control.id)}
                                />
                                <span className="font-mono text-xs font-semibold text-blue-700">{control.control_ref}</span>
                                <span>{control.title}</span>
                            </label>
                        ))}
                        {controls.length === 0 && <p className="text-sm text-gray-400">No active controls available.</p>}
                    </div>
                </div>

                <div className="card bg-white p-6 shadow-sm rounded-lg border border-gray-200">
                    <div className="text-base font-semibold text-gray-900 border-b border-gray-100 pb-3 mb-2">
                        Risks Bearing on this Incident
                    </div>
                    <p className="mb-3 text-xs text-gray-500">
                        Linking risks helps track risk appetite consumption and loss event correlations.
                    </p>
                    <div className="max-h-56 space-y-2 overflow-y-auto divide-y divide-gray-100">
                        {risks.map((risk) => (
                            <label key={risk.id} className="flex items-center gap-2 pt-2 text-sm text-gray-700 hover:text-gray-900 cursor-pointer">
                                <input
                                    type="checkbox"
                                    className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                    checked={data.risk_ids.includes(risk.id)}
                                    onChange={() => toggle('risk_ids', risk.id)}
                                />
                                <span className="font-mono text-xs font-semibold text-indigo-700">{risk.code}</span>
                                <span>{risk.title}</span>
                            </label>
                        ))}
                        {risks.length === 0 && <p className="text-sm text-gray-400">No risks on the register.</p>}
                    </div>
                </div>

                <div className="flex justify-end gap-3">
                    <button
                        type="button"
                        onClick={() => window.history.back()}
                        className="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        className="btn-primary px-5 py-2 inline-flex items-center gap-2"
                        disabled={processing}
                    >
                        {processing ? 'Recording…' : 'Record Incident'}
                    </button>
                </div>
            </form>
        </AppLayout>
    );
}
