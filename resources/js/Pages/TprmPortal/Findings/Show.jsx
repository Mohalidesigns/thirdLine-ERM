import { useForm } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-08. The vendor responds; it never closes.
 *
 * A portal that let a vendor mark its own finding remediated would produce a
 * register in which every finding is closed and none is fixed. The page says
 * so plainly rather than leaving somebody hunting for a button.
 */
export default function Show({ finding = {}, thread = [] }) {
    const form = useForm({ body: '' });

    return (
        <PortalLayout title={finding.reference}>
            <div className="mb-4">
                <p className="font-mono text-xs text-gray-500">{finding.reference}</p>
                <h1 className="text-lg font-semibold text-gray-900">{finding.title}</h1>
                <p className="mt-1 text-sm text-gray-600">
                    {finding.severity_label} · {finding.status_label}
                    {finding.target_date && <> · due {finding.target_date}</>}
                </p>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2 space-y-4">
                    <div className="rounded-lg border border-gray-200 bg-white p-4">
                        <h2 className="text-sm font-semibold text-gray-900">What was found</h2>
                        <p className="mt-2 whitespace-pre-wrap text-sm text-gray-700">{finding.description}</p>
                        {finding.regulatory_citation && (
                            <p className="mt-3 text-xs text-gray-500">Cited: {finding.regulatory_citation}</p>
                        )}
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">
                        Describe what you have done in the thread and attach your evidence there. Your client
                        verifies and closes the finding — you will see the status change here when they do.
                    </div>
                </div>

                <aside className="rounded-lg border border-gray-200 bg-white">
                    <h2 className="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">
                        Messages
                    </h2>

                    <div className="max-h-96 space-y-3 overflow-y-auto p-4">
                        {thread.length === 0 && (
                            <p className="text-sm text-gray-500">Nothing yet.</p>
                        )}
                        {thread.map((message) => (
                            <div
                                key={message.id}
                                className={`rounded p-2 text-sm ${
                                    message.from_vendor ? 'bg-blue-50 text-blue-900' : 'bg-gray-100 text-gray-800'
                                }`}
                            >
                                <p className="whitespace-pre-wrap">{message.body}</p>
                                <p className="mt-1 text-xs opacity-70">
                                    {message.from_vendor ? 'You' : 'Reviewer'} · {message.at}
                                </p>
                            </div>
                        ))}
                    </div>

                    <form
                        className="border-t border-gray-100 p-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(route('tprm-portal.findings.messages.store', finding.uuid), {
                                preserveScroll: true,
                                onSuccess: () => form.reset('body'),
                            });
                        }}
                    >
                        <textarea
                            className="w-full rounded border border-gray-200 p-2 text-sm"
                            rows="3"
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            placeholder="What have you done about this?"
                        />
                        <button
                            type="submit"
                            disabled={form.processing || form.data.body.trim() === ''}
                            className="mt-2 w-full rounded bg-gray-900 px-3 py-1.5 text-sm text-white disabled:opacity-40"
                        >
                            Send
                        </button>
                    </form>
                </aside>
            </div>
        </PortalLayout>
    );
}
