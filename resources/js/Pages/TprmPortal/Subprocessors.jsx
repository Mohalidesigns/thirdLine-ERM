import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-06 — the vendor declares who it relies on.
 *
 * THE CONSENT POSITION IS STATED UP FRONT, whichever way it falls. A vendor
 * whose contract requires consent needs to know before they switch provider,
 * not after; and where the contract is silent we say so rather than implying
 * a right to object that the bank does not have.
 */
export default function Subprocessors({ subprocessors = [], consent = {} }) {
    const [declaring, setDeclaring] = useState(false);

    return (
        <PortalLayout title="Sub-processors">
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="max-w-2xl">
                    <h1 className="text-lg font-semibold text-gray-900">Your sub-processors</h1>
                    <p className="text-sm text-gray-600">
                        The companies you rely on to deliver our service. Declaring them here is what stops a
                        client discovering one in your SOC 2 that you never mentioned — which becomes a finding
                        against you.
                    </p>
                </div>
                <button
                    type="button"
                    className="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    onClick={() => setDeclaring(true)}
                >
                    Declare one
                </button>
            </div>

            <div className={`mb-4 rounded border px-4 py-3 text-sm ${
                consent.required
                    ? 'border-amber-200 bg-amber-50 text-amber-900'
                    : 'border-gray-200 bg-gray-50 text-gray-700'
            }`}>
                {consent.required
                    ? 'Your contract requires our consent before you use a new sub-processor. Declare the change here and wait for confirmation before switching.'
                    : 'Your contract does not require our consent for a change of sub-processor — but tell us anyway, so it is on the record.'}
                <span className="block text-xs opacity-80">{consent.basis}</span>
            </div>

            {subprocessors.length === 0 ? (
                <div className="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
                    None declared yet.
                </div>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                    <table className="min-w-full divide-y divide-gray-200 text-sm">
                        <thead className="bg-gray-50">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Company</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">What they do</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Country</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Status</th>
                                <th scope="col" className="px-4 py-2 text-left font-medium text-gray-600">Declared</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {subprocessors.map((row) => (
                                <tr key={row.id}>
                                    <th scope="row" className="px-4 py-2 text-left font-medium text-gray-900">{row.name}</th>
                                    <td className="px-4 py-2">{row.service_description ?? '—'}</td>
                                    <td className="px-4 py-2">{row.country ?? '—'}</td>
                                    <td className="px-4 py-2">
                                        {row.status === 'confirmed'
                                            ? <span className="text-green-700">Accepted</span>
                                            : <span className="text-amber-700">Awaiting your client</span>}
                                    </td>
                                    <td className="px-4 py-2 text-xs text-gray-500">{row.declared_at ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {declaring && <DeclareDialog consent={consent} onClose={() => setDeclaring(false)} />}
        </PortalLayout>
    );
}

function DeclareDialog({ consent, onClose }) {
    const form = useForm({ name: '', service_description: '', country_of_processing: '', criticality: '' });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/30 p-4">
            <form
                className="w-full max-w-lg rounded-lg bg-white p-5 shadow-xl"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(route('tprm-portal.subprocessors.declare'), { onSuccess: onClose });
                }}
            >
                <h2 className="text-base font-semibold text-gray-900">Declare a sub-processor</h2>
                {consent.required && (
                    <p className="mt-1 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-900">
                        This will go to your client for approval before you may use them.
                    </p>
                )}

                <div className="mt-3 space-y-3">
                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">Company name</label>
                        <input
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        {form.errors.name && <p className="mt-1 text-xs text-red-700">{form.errors.name}</p>}
                    </div>

                    <div>
                        <label className="mb-1 block text-sm font-medium text-gray-700">What they do for us</label>
                        <textarea
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            rows="2"
                            value={form.data.service_description}
                            onChange={(event) => form.setData('service_description', event.target.value)}
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Country (ISO code)</label>
                            <input
                                maxLength="2"
                                className="w-full rounded border border-gray-200 p-2 text-sm uppercase"
                                value={form.data.country_of_processing}
                                onChange={(event) => form.setData('country_of_processing', event.target.value.toUpperCase())}
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">How critical</label>
                            <select
                                className="w-full rounded border border-gray-200 p-2 text-sm"
                                value={form.data.criticality}
                                onChange={(event) => form.setData('criticality', event.target.value)}
                            >
                                <option value="">Not sure</option>
                                <option value="critical">Critical</option>
                                <option value="important">Important</option>
                                <option value="standard">Standard</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="px-3 py-1.5 text-sm text-gray-600" onClick={onClose}>Cancel</button>
                    <button type="submit" disabled={form.processing} className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white">
                        Declare
                    </button>
                </div>
            </form>
        </div>
    );
}
